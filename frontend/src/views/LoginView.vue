<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getErrorCode, getErrorMessage } from '../composables/useApi'
import {
  cancelPasskeyRequest,
  passkeyAutofillSupported,
  passkeyCancelled,
  passkeysSupported,
  usePasskeys,
} from '../composables/usePasskeys'
import { useAuthStore } from '../stores/auth'
import type { SignInResponse } from '../types'
import TurnstileWidget from '../components/TurnstileWidget.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const remember = ref(false)
const turnstileToken = ref('')
const turnstile = useTemplateRef<InstanceType<typeof TurnstileWidget>>('turnstile')
const siteKey = ref<string | null>(null)
const signupOpen = ref(false)
const error = ref('')
const busy = ref(false)
const passkeys = usePasskeys()
const canUsePasskeys = passkeysSupported()
const passkeyBusy = ref(false)
/** Whether the username field offers saved passkeys, as this browser can. */
let autofill = false

/** Where to go once signed in: back where the sign-in link was, if it was on this site. */
const redirect = computed(() => {
  const target = route.query.redirect
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
    ? target
    : '/'
})

const ready = computed(
  () =>
    username.value !== '' &&
    password.value !== '' &&
    (siteKey.value === null || turnstileToken.value !== ''),
)

if (auth.isSignedIn) void router.replace(redirect.value)

onMounted(async () => {
  autofill = canUsePasskeys && (await passkeyAutofillSupported())
  if (autofill) void offerPasskeys()
  const config = await auth.loadConfig().catch(() => null)
  siteKey.value = config?.turnstile_site_key ?? null
  signupOpen.value = config?.signup_open ?? false
})

// The autofill offer waits for an answer until the page goes.
onBeforeUnmount(cancelPasskeyRequest)

async function finish(result: SignInResponse | undefined) {
  await auth.signedIn(result)
  void router.replace(redirect.value)
}

/**
 * Offer saved passkeys in the username field's autofill. It waits until one
 * is picked, the button asks instead, or the page closes. A passkey the
 * server turned down (or answering a challenge more than five minutes old)
 * is reported, and the offer made again; any other failure ends it.
 */
async function offerPasskeys() {
  try {
    await finish(await passkeys.signIn(() => remember.value, true))
  } catch (e) {
    if (passkeyCancelled(e)) return
    error.value = getErrorMessage(e, 'Could not sign in with that passkey.')
    if (['PasskeyExpired', 'PasskeyNotAccepted'].includes(getErrorCode(e))) void offerPasskeys()
  }
}

async function signInWithPasskey() {
  if (passkeyBusy.value) return
  passkeyBusy.value = true
  error.value = ''
  try {
    await finish(await passkeys.signIn(() => remember.value))
  } catch (e) {
    if (!passkeyCancelled(e)) error.value = getErrorMessage(e, 'Could not sign in with a passkey.')
    // Asking here ended the autofill offer; make it again.
    if (autofill && !auth.isSignedIn) void offerPasskeys()
  } finally {
    passkeyBusy.value = false
  }
}

async function signIn() {
  if (!ready.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    await auth.signIn({
      username: username.value,
      password: password.value,
      remember: remember.value,
      turnstile_token: turnstileToken.value || undefined,
    })
    void router.replace(redirect.value)
  } catch (e) {
    error.value = getErrorMessage(e, 'Could not sign in. Please try again.')
    password.value = ''
    // A Turnstile token passes once; the next try needs a new one.
    turnstile.value?.reset()
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-5-tablet is-4-desktop">
          <form class="box" @submit.prevent="signIn">
            <h2 class="title is-4 has-text-centered">Sign in</h2>
            <div class="field">
              <label class="label" for="login-username">Username</label>
              <div class="control has-icons-left">
                <!-- The first field: focusing it is what arriving at a sign-in page is for. -->
                <!-- eslint-disable vuejs-accessibility/no-autofocus -->
                <input
                  id="login-username"
                  v-model="username"
                  class="input"
                  type="text"
                  autocomplete="username webauthn"
                  autocapitalize="none"
                  spellcheck="false"
                  autofocus
                />
                <!-- eslint-enable vuejs-accessibility/no-autofocus -->
                <span class="icon is-left"><i class="fa-solid fa-user" /></span>
              </div>
            </div>
            <div class="field">
              <label class="label" for="login-password">Password</label>
              <div class="control has-icons-left">
                <input
                  id="login-password"
                  v-model="password"
                  class="input"
                  type="password"
                  autocomplete="current-password"
                />
                <span class="icon is-left"><i class="fa-solid fa-lock" /></span>
              </div>
            </div>
            <div class="field">
              <label class="checkbox">
                <input v-model="remember" type="checkbox" />
                Keep me signed in for 30 days
              </label>
            </div>
            <TurnstileWidget
              v-if="siteKey"
              ref="turnstile"
              v-model="turnstileToken"
              :site-key="siteKey"
              action="login"
            />
            <p v-if="error" class="help is-danger mb-3" role="alert">{{ error }}</p>
            <button
              type="submit"
              class="button is-primary is-fullwidth"
              :class="{ 'is-loading': busy }"
              :disabled="!ready"
            >
              Sign in
            </button>
            <template v-if="canUsePasskeys">
              <p class="or" aria-hidden="true">or</p>
              <button
                type="button"
                class="button is-fullwidth"
                :class="{ 'is-loading': passkeyBusy }"
                @click="signInWithPasskey"
              >
                <span class="icon"><i class="fa-solid fa-key" /></span>
                <span>Sign in with a passkey</span>
              </button>
            </template>
            <p v-if="signupOpen" class="has-text-centered mt-4">
              No account?
              <router-link :to="{ name: 'register', query: route.query }">Create one</router-link>
            </p>
          </form>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.or {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  margin: var(--sp-3) 0;
  color: var(--text-2);
  font-size: var(--t-sm);
}

.or::before,
.or::after {
  content: '';
  flex: 1;
  border-top: 1px solid var(--border);
}
</style>

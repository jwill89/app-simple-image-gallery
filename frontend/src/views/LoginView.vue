<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getErrorMessage } from '../composables/useApi'
import { useAuthStore } from '../stores/auth'
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
  const config = await auth.loadConfig().catch(() => null)
  siteKey.value = config?.turnstile_site_key ?? null
  signupOpen.value = config?.signup_open ?? false
})

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
                  autocomplete="username"
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

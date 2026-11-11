<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getErrorMessage, getFieldError } from '../composables/useApi'
import { useAuthStore } from '../stores/auth'
import TurnstileWidget from '../components/TurnstileWidget.vue'
import EmptyState from '../components/EmptyState.vue'

const USERNAME_PATTERN = /^[A-Za-z0-9._-]{3,32}$/
const MIN_PASSWORD = 8

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const confirm = ref('')
const remember = ref(false)
const turnstileToken = ref('')
const turnstile = useTemplateRef<InstanceType<typeof TurnstileWidget>>('turnstile')
const loaded = ref(false)
const signupOpen = ref(true)
const siteKey = ref<string | null>(null)
const error = ref('')
const usernameError = ref('')
const passwordError = ref('')
const busy = ref(false)

const redirect = computed(() => {
  const target = route.query.redirect
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
    ? target
    : '/'
})

const usernameHint = computed(() =>
  username.value === '' || USERNAME_PATTERN.test(username.value)
    ? ''
    : '3 to 32 letters, digits, dots, dashes or underscores.',
)
const passwordHint = computed(() =>
  password.value === '' || password.value.length >= MIN_PASSWORD
    ? ''
    : `At least ${MIN_PASSWORD} characters.`,
)
const confirmHint = computed(() =>
  confirm.value === '' || confirm.value === password.value ? '' : "The passwords don't match.",
)
const ready = computed(
  () =>
    USERNAME_PATTERN.test(username.value) &&
    password.value.length >= MIN_PASSWORD &&
    confirm.value === password.value &&
    (siteKey.value === null || turnstileToken.value !== ''),
)

if (auth.isSignedIn) {
  void router.replace(redirect.value)
}

onMounted(async () => {
  const config = await auth.loadConfig().catch(() => null)
  signupOpen.value = config?.signup_open ?? true
  siteKey.value = config?.turnstile_site_key ?? null
  loaded.value = true
})

async function register() {
  if (!ready.value || busy.value) {
    return
  }
  busy.value = true
  error.value = usernameError.value = passwordError.value = ''
  try {
    await auth.register({
      username: username.value,
      password: password.value,
      remember: remember.value,
      turnstile_token: turnstileToken.value || undefined,
    })
    void router.replace(redirect.value)
  } catch (e) {
    usernameError.value = getFieldError(e, 'username')
    passwordError.value = getFieldError(e, 'password')
    if (!usernameError.value && !passwordError.value) {
      error.value = getErrorMessage(e, 'Could not create the account. Please try again.')
    }
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
          <EmptyState
            v-if="loaded && !signupOpen"
            icon="fa-solid fa-user-lock"
            title="New accounts aren't being accepted."
            hint="If you already have one, sign in."
          >
            <router-link :to="{ name: 'login', query: route.query }" class="button">
              Sign in
            </router-link>
          </EmptyState>
          <form v-else class="box" @submit.prevent="register">
            <h1 class="title is-4 has-text-centered">Create an account</h1>
            <p class="account-why">
              An account keeps your favorites and lists on the server, hides the tags you block, and
              saves your searches.
            </p>
            <div class="field">
              <label class="label" for="register-username">Username</label>
              <div class="control has-icons-left">
                <!-- eslint-disable vuejs-accessibility/no-autofocus -- the first field: focusing it is what arriving at a sign-up page is for -->
                <input
                  id="register-username"
                  v-model="username"
                  class="input"
                  :class="{ 'is-danger': usernameHint || usernameError }"
                  type="text"
                  autocomplete="username"
                  autocapitalize="none"
                  spellcheck="false"
                  autofocus
                />
                <!-- eslint-enable vuejs-accessibility/no-autofocus -->
                <span class="icon is-left"><font-awesome-icon icon="fa-solid fa-user" /></span>
              </div>
              <p v-if="usernameHint || usernameError" class="help is-danger">
                {{ usernameError || usernameHint }}
              </p>
            </div>
            <div class="field">
              <label class="label" for="register-password">Password</label>
              <div class="control has-icons-left">
                <input
                  id="register-password"
                  v-model="password"
                  class="input"
                  :class="{ 'is-danger': passwordHint || passwordError }"
                  type="password"
                  autocomplete="new-password"
                />
                <span class="icon is-left"><font-awesome-icon icon="fa-solid fa-lock" /></span>
              </div>
              <p v-if="passwordHint || passwordError" class="help is-danger">
                {{ passwordError || passwordHint }}
              </p>
            </div>
            <div class="field">
              <label class="label" for="register-confirm">Password again</label>
              <div class="control has-icons-left">
                <input
                  id="register-confirm"
                  v-model="confirm"
                  class="input"
                  :class="{ 'is-danger': confirmHint }"
                  type="password"
                  autocomplete="new-password"
                />
                <span class="icon is-left"><font-awesome-icon icon="fa-solid fa-lock" /></span>
              </div>
              <p v-if="confirmHint" class="help is-danger">{{ confirmHint }}</p>
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
              action="register"
            />
            <p v-if="error" class="help is-danger mb-3" role="alert">{{ error }}</p>
            <button
              type="submit"
              class="button is-primary is-fullwidth"
              :class="{ 'is-loading': busy }"
              :disabled="!ready"
            >
              Create account
            </button>
            <p class="has-text-centered mt-4">
              Have one already?
              <router-link :to="{ name: 'login', query: route.query }">Sign in</router-link>
            </p>
          </form>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.account-why {
  color: var(--text-2);
  font-size: var(--t-sm);
  margin-bottom: var(--sp-4);
}
</style>

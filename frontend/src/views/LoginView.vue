<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useApi, setAuthToken, hasAuthToken } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { LoginResponse } from '../types'

const router = useRouter()
const api = useApi()
const passwordInput = ref('')
const authError = ref<string | null>(null)
const authLoading = ref(false)

if (hasAuthToken()) {
  void router.replace('/')
}

async function login() {
  authLoading.value = true
  authError.value = null
  try {
    const result = await api.post<LoginResponse>(endpoints.auth.login, {
      password: passwordInput.value,
    })
    if (!result) throw new Error('No token returned')
    setAuthToken(result.token)
    passwordInput.value = ''
    void router.replace('/')
  } catch {
    authError.value = 'Invalid password'
  } finally {
    authLoading.value = false
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-4">
          <div class="box">
            <h2 class="title is-4 has-text-centered">Admin Login</h2>
            <div class="field">
              <label class="label" for="login-password">Password</label>
              <div class="control has-icons-left">
                <!-- The page's only field: focusing it is what arriving at a login page is for. -->
                <!-- eslint-disable vuejs-accessibility/no-autofocus -->
                <input
                  id="login-password"
                  v-model="passwordInput"
                  class="input"
                  type="password"
                  placeholder="Enter admin password"
                  autofocus
                  @keyup.enter="login"
                />
                <!-- eslint-enable vuejs-accessibility/no-autofocus -->
                <span class="icon is-left">
                  <i class="fa-solid fa-lock" />
                </span>
              </div>
              <p v-if="authError" class="help is-danger">
                {{ authError }}
              </p>
            </div>
            <button
              type="button"
              class="button is-primary is-fullwidth"
              :class="{ 'is-loading': authLoading }"
              @click="login"
            >
              Login
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

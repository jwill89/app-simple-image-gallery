import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  useApi,
  setAuthToken,
  clearAuthToken,
  hasAuthToken,
  whenAuthTokenCleared,
} from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { AuthConfig, LoginResponse, User } from '../types'
import { useAccountStore } from './account'
import { useFavoritesStore } from './favorites'
import { useGalleryStore } from './gallery'
import { useToastStore } from './toast'

/** What a sign-in or sign-up sends; the Turnstile token when the server checks for bots. */
export interface Credentials {
  username: string
  password: string
  remember: boolean
  turnstile_token?: string
}

/**
 * Who is signed in, if anyone. Signing in loads the account's own things —
 * its lists, blocked tags and saved searches — and signing out (or a 401
 * finding the token expired) forgets them, so the app is back to what any
 * visitor sees.
 */
export const useAuthStore = defineStore('auth', () => {
  const api = useApi()
  const user = ref<User | null>(null)
  const config = ref<AuthConfig | null>(null)

  const isSignedIn = computed(() => user.value !== null)
  const isAdmin = computed(() => user.value?.is_admin === true)

  whenAuthTokenCleared(() => {
    if (user.value === null) return
    user.value = null
    useFavoritesStore().signedOut()
    useAccountStore().clear()
    useGalleryStore().listingsChanged()
  })

  /** At start-up: the account the stored token signs in, if it still does. */
  async function restore(): Promise<void> {
    if (!hasAuthToken()) return
    try {
      user.value = (await api.get<User>(endpoints.auth.me)) ?? null
    } catch {
      // A 401 has cleared the token; anything else leaves it for next time.
      user.value = null
    }
    if (user.value !== null) await loadAccount()
  }

  /** Whether sign-up is open, and Turnstile's site key: asked once. */
  async function loadConfig(): Promise<AuthConfig> {
    config.value ??= (await api.get<AuthConfig>(endpoints.auth.config)) ?? {
      signup_open: false,
      turnstile_site_key: null,
    }
    return config.value
  }

  async function signIn(credentials: Credentials): Promise<void> {
    await start(await api.post<LoginResponse>(endpoints.auth.login, credentials), credentials)
  }

  async function register(credentials: Credentials): Promise<void> {
    await start(await api.post<LoginResponse>(endpoints.auth.register, credentials), credentials)
  }

  async function signOut(): Promise<void> {
    // Revoked on the server too, so a copy of the token stops working. Signed
    // out here either way: a failed revoke still expires on its own.
    await api.del(endpoints.auth.logout).catch(() => undefined)
    clearAuthToken()
  }

  async function start(result: LoginResponse | undefined, credentials: Credentials): Promise<void> {
    if (!result) throw new Error('The server sent no login token.')
    setAuthToken(result.token, credentials.remember)
    user.value = result.user
    useGalleryStore().listingsChanged()
    await loadAccount()
  }

  async function loadAccount(): Promise<void> {
    try {
      await Promise.all([useFavoritesStore().signedIn(), useAccountStore().load()])
    } catch {
      useToastStore().warning(
        'You are signed in, but your lists and settings could not be loaded. Reload the page to try again.',
        8000,
        'Account',
      )
    }
  }

  return {
    user,
    config,
    isSignedIn,
    isAdmin,
    restore,
    loadConfig,
    signIn,
    register,
    signOut,
  }
})

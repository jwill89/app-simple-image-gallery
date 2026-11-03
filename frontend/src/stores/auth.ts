import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useApi, getErrorStatus, whenUnauthorized } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { AuthConfig, SessionResponse, SignInResponse, User } from '../types'
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
 * Who is signed in, if anyone. The server keeps that in a session cookie the
 * page can't read, so the store asks once as the app starts. Signing in loads
 * the account's own things — its lists, blocked tags and saved searches — and
 * signing out (or a 401: the session ran out, or ended elsewhere) forgets
 * them, so the app is back to what any visitor sees.
 */
export const useAuthStore = defineStore('auth', () => {
  const api = useApi()
  const user = ref<User | null>(null)
  const config = ref<AuthConfig | null>(null)

  const isSignedIn = computed(() => user.value !== null)
  const isAdmin = computed(() => user.value?.is_admin === true)

  whenUnauthorized(sessionEnded)

  /** At start-up: the account this browser's session signs in, if any. */
  async function restore(): Promise<void> {
    try {
      user.value = (await api.get<SessionResponse>(endpoints.auth.session))?.user ?? null
    } catch {
      // Signed out, or the server is out of reach: browse as anyone may.
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
    await signedIn(await api.post<SignInResponse>(endpoints.auth.login, credentials))
  }

  async function register(credentials: Credentials): Promise<void> {
    await signedIn(await api.post<SignInResponse>(endpoints.auth.register, credentials))
  }

  /**
   * Sign this browser out. Only the server can end the session (its cookie is
   * out of the page's reach), so a failure is thrown, and the app stays signed
   * in rather than pretending; a 401 means it already was signed out.
   */
  async function signOut(): Promise<void> {
    try {
      await api.del(endpoints.auth.session)
    } catch (e) {
      if (getErrorStatus(e) !== 401) throw e
    }
    sessionEnded()
  }

  /** A sign-in's answer: the account this browser's session now names. */
  async function signedIn(result: SignInResponse | undefined): Promise<void> {
    if (!result) throw new Error('The server did not say who signed in.')
    user.value = result.user
    useGalleryStore().listingsChanged()
    await loadAccount()
  }

  /** The server has ended this browser's session: forget the account here. */
  function sessionEnded(): void {
    if (user.value === null) return
    user.value = null
    useFavoritesStore().signedOut()
    useAccountStore().clear()
    useGalleryStore().listingsChanged()
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
    signedIn,
    register,
    signOut,
    sessionEnded,
  }
})

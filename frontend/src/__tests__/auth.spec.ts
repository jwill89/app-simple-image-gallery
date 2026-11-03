import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type * as UseApiModule from '../composables/useApi'
import { ApiError } from '../composables/useApi'
import { useAuthStore } from '../stores/auth'

// Who is signed in: the server's session cookie says, which the page can't
// read, so the store asks it (faked here by mocking useApi's requests).

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({ get, post, put: vi.fn(), patch: vi.fn(), del, upload: vi.fn() }),
  }
})

const READER = { id: 1, username: 'reader', is_admin: false, created_at: '2026-11-03T00:00:00Z' }

/** The server, signed in as `user` (or no one): its session, and the account's things. */
function server(user: typeof READER | null) {
  get.mockImplementation((url: string) => {
    if (url === '/auth/session') return Promise.resolve({ user })
    if (url === '/lists')
      return Promise.resolve([{ list_id: 1, name: 'Favorites', is_default: true, media_count: 0 }])
    return Promise.resolve([])
  })
}

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  del.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('as the app starts', () => {
  it('asks the server who this browser is signed in as', async () => {
    server(READER)
    const auth = useAuthStore()
    await auth.restore()

    expect(get).toHaveBeenCalledWith('/auth/session')
    expect(auth.user).toEqual(READER)
    expect(auth.isSignedIn).toBe(true)
  })

  it('is signed out when the session names no one, or the server is out of reach', async () => {
    server(null)
    const auth = useAuthStore()
    await auth.restore()
    expect(auth.isSignedIn).toBe(false)

    get.mockRejectedValue(new Error('offline'))
    await auth.restore()
    expect(auth.isSignedIn).toBe(false)
  })
})

describe('signing in and out', () => {
  it('takes the account a sign-in names; there is no token to keep', async () => {
    server(READER)
    post.mockResolvedValueOnce({ user: READER })
    const auth = useAuthStore()
    await auth.signIn({ username: 'reader', password: 'long-enough', remember: true })

    expect(post).toHaveBeenCalledWith('/auth/login', {
      username: 'reader',
      password: 'long-enough',
      remember: true,
    })
    expect(auth.user).toEqual(READER)
    expect(localStorage.length + sessionStorage.length).toBe(0)
  })

  it('stays signed in when the server could not end the session', async () => {
    server(READER)
    const auth = useAuthStore()
    await auth.restore()

    del.mockRejectedValueOnce(new ApiError(500, 'ServerError', 'Something went wrong.'))
    await expect(auth.signOut()).rejects.toBeInstanceOf(ApiError)
    expect(auth.isSignedIn).toBe(true)

    del.mockResolvedValueOnce(undefined)
    await auth.signOut()
    expect(del).toHaveBeenLastCalledWith('/auth/session')
    expect(auth.isSignedIn).toBe(false)
  })

  it('counts a session already gone (401) as signed out', async () => {
    server(READER)
    const auth = useAuthStore()
    await auth.restore()

    del.mockRejectedValueOnce(new ApiError(401, 'Unauthorized', 'Authentication is required.'))
    await auth.signOut()
    expect(auth.isSignedIn).toBe(false)
  })

  it('forgets the account when any request finds the session ended', async () => {
    server(READER)
    const auth = useAuthStore()
    await auth.restore()

    // The real useApi, answered 401.
    const actual = await vi.importActual<typeof UseApiModule>('../composables/useApi')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ ok: false, status: 401, text: () => Promise.resolve('{}') }),
    )
    await expect(actual.useApi().get('/lists')).rejects.toBeInstanceOf(ApiError)
    expect(auth.isSignedIn).toBe(false)
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { WebAuthnError } from '@simplewebauthn/browser'
import type * as WebAuthnModule from '@simplewebauthn/browser'
import type * as UseApiModule from '../composables/useApi'
import { ApiError } from '../composables/useApi'
import { passkeyCancelled, passkeyErrorMessage, usePasskeys } from '../composables/usePasskeys'

// Passkeys in the browser: options from the server, the browser's answer
// (faked here, as the server is, by mocking useApi), and the answer sent back.

const { post, startAuthentication, startRegistration } = vi.hoisted(() => ({
  post: vi.fn(),
  startAuthentication: vi.fn(),
  startRegistration: vi.fn(),
}))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({
      get: vi.fn(),
      post,
      put: vi.fn(),
      patch: vi.fn(),
      del: vi.fn(),
      upload: vi.fn(),
    }),
  }
})

vi.mock('@simplewebauthn/browser', async (importOriginal) => ({
  ...(await importOriginal<typeof WebAuthnModule>()),
  startAuthentication,
  startRegistration,
}))

const OPTIONS = { challenge: 'abc', rpId: 'gallery.test', userVerification: 'required' }
const ANSWER = { id: 'cred', rawId: 'cred', type: 'public-key', response: {} }

beforeEach(() => {
  post.mockReset()
  startAuthentication.mockReset().mockResolvedValue(ANSWER)
  startRegistration.mockReset().mockResolvedValue(ANSWER)
})

describe('signing in', () => {
  it("answers the server's challenge, with remember as it is when the answer comes", async () => {
    let remember = false
    post.mockResolvedValueOnce(OPTIONS).mockResolvedValueOnce({ user: { username: 'reader' } })
    startAuthentication.mockImplementation(() => {
      remember = true
      return Promise.resolve(ANSWER)
    })

    const result = await usePasskeys().signIn(() => remember, true)

    expect(post).toHaveBeenNthCalledWith(1, '/auth/passkey/options', {})
    expect(startAuthentication).toHaveBeenCalledWith({
      optionsJSON: OPTIONS,
      useBrowserAutofill: true,
    })
    expect(post).toHaveBeenNthCalledWith(2, '/auth/passkey', { credential: ANSWER, remember: true })
    expect(result).toEqual({ user: { username: 'reader' } })
  })

  it('sends nothing back when the browser gave no answer', async () => {
    post.mockResolvedValueOnce(OPTIONS)
    startAuthentication.mockRejectedValue(new DOMException('cancelled', 'NotAllowedError'))

    await expect(usePasskeys().signIn(() => false)).rejects.toBeInstanceOf(DOMException)
    expect(post).toHaveBeenCalledOnce()
  })
})

describe('adding one', () => {
  it('sends the password first, then the answer with its name', async () => {
    post.mockResolvedValueOnce(OPTIONS).mockResolvedValueOnce({ id: 1, name: 'Laptop' })

    await usePasskeys().add('long-enough', 'Laptop')

    expect(post).toHaveBeenNthCalledWith(1, '/account/passkeys/options', {
      password: 'long-enough',
    })
    expect(startRegistration).toHaveBeenCalledWith({ optionsJSON: OPTIONS })
    expect(post).toHaveBeenNthCalledWith(2, '/account/passkeys', {
      credential: ANSWER,
      name: 'Laptop',
    })
  })

  it('leaves the name to the server when none is given', async () => {
    post.mockResolvedValueOnce(OPTIONS).mockResolvedValueOnce({ id: 1, name: 'Windows Hello' })

    await usePasskeys().add('long-enough', '')

    expect(post).toHaveBeenLastCalledWith('/account/passkeys', { credential: ANSWER })
  })
})

describe('when one fails', () => {
  const cause = new Error('cause')

  it('tells a cancelled or replaced request from a real failure', () => {
    expect(passkeyCancelled(new DOMException('closed', 'NotAllowedError'))).toBe(true)
    expect(
      passkeyCancelled(
        new WebAuthnError({ message: 'aborted', code: 'ERROR_CEREMONY_ABORTED', cause }),
      ),
    ).toBe(true)
    expect(passkeyCancelled(new ApiError(401, 'PasskeyNotAccepted', 'Not accepted.'))).toBe(false)
  })

  it("says a passkey is already added, and passes the server's message on", () => {
    const again = new WebAuthnError({
      message: 'previously registered',
      code: 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED',
      cause,
    })
    expect(passkeyErrorMessage(again, 'Could not add it.')).toBe(
      'That passkey is already added to your account.',
    )
    expect(
      passkeyErrorMessage(
        new ApiError(403, 'IncorrectPassword', "That isn't your current password."),
        'x',
      ),
    ).toBe("That isn't your current password.")
  })
})

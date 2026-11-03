import {
  browserSupportsWebAuthn,
  browserSupportsWebAuthnAutofill,
  startAuthentication,
  startRegistration,
  WebAuthnAbortService,
  WebAuthnError,
  type PublicKeyCredentialCreationOptionsJSON,
  type PublicKeyCredentialRequestOptionsJSON,
} from '@simplewebauthn/browser'
import { getErrorMessage, useApi } from './useApi'
import { endpoints } from '../api/endpoints'
import type { Passkey, SignInResponse } from '../types'

/*
 * Passkeys in the browser. Each use takes two requests: the server sends
 * WebAuthn options with a challenge, the browser asks the person's phone,
 * computer or password manager to answer it, and the answer goes back for the
 * server to check. @simplewebauthn/browser does the asking, in browsers old
 * and new.
 */

/** Whether this browser can use passkeys at all. */
export function passkeysSupported(): boolean {
  return browserSupportsWebAuthn()
}

/** Whether it can offer saved passkeys in the username field's autofill. */
export function passkeyAutofillSupported(): Promise<boolean> {
  return browserSupportsWebAuthnAutofill()
}

/**
 * Whether a passkey request ended without an answer: the person closed the
 * prompt, it timed out, or a newer request replaced it. Nothing to report.
 */
export function passkeyCancelled(e: unknown): boolean {
  return (
    (e instanceof WebAuthnError && e.code === 'ERROR_CEREMONY_ABORTED') ||
    (e instanceof DOMException && (e.name === 'NotAllowedError' || e.name === 'AbortError'))
  )
}

/** What to tell the person about a passkey request that failed. */
export function passkeyErrorMessage(e: unknown, fallback: string): string {
  if (e instanceof WebAuthnError && e.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
    return 'That passkey is already added to your account.'
  }
  return getErrorMessage(e, fallback)
}

/** Stop a passkey request still waiting for its answer (the autofill offer, as the page closes). */
export function cancelPasskeyRequest(): void {
  WebAuthnAbortService.cancelCeremony()
}

export function usePasskeys() {
  const api = useApi()

  /**
   * Sign in with a passkey. With autofill, the request waits for the person
   * to pick a passkey from the username field's suggestions. remember is read
   * when the answer comes, so the checkbox can change while it waits.
   */
  async function signIn(
    remember: () => boolean,
    autofill = false,
  ): Promise<SignInResponse | undefined> {
    const optionsJSON = await api.post<PublicKeyCredentialRequestOptionsJSON>(
      endpoints.auth.passkeyOptions,
      {},
    )
    if (!optionsJSON) throw new Error('The server sent no passkey options.')
    const credential = await startAuthentication({ optionsJSON, useBrowserAutofill: autofill })
    return api.post<SignInResponse>(endpoints.auth.passkey, { credential, remember: remember() })
  }

  /** Add a passkey to the signed-in account; its password comes first. */
  async function add(password: string, name: string): Promise<Passkey | undefined> {
    const optionsJSON = await api.post<PublicKeyCredentialCreationOptionsJSON>(
      endpoints.account.passkeyOptions,
      { password },
    )
    if (!optionsJSON) throw new Error('The server sent no passkey options.')
    const credential = await startRegistration({ optionsJSON })
    return api.post<Passkey>(endpoints.account.passkeys, {
      credential,
      ...(name === '' ? {} : { name }),
    })
  }

  return { signIn, add }
}

/**
 * This answer's Content-Security-Policy nonce, from the <meta property="csp-nonce"> the
 * build writes and the server fills in (vite.config.ts's html.cspNonce). Read as the
 * property: the browser hides the attribute's value from the page.
 */
export function cspNonce(): string {
  return document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce ?? ''
}

import { afterEach, describe, expect, it } from 'vitest'
import { loadTurnstile } from '../composables/useTurnstile'

/**
 * Turnstile's script is added to the page at run time, so the Content-Security-Policy
 * admits it only with the answer's nonce, which the page carries in its csp-nonce meta.
 */
describe('loadTurnstile', () => {
  afterEach(() => {
    document.head.innerHTML = ''
  })

  it('gives the script it adds the page’s nonce, and loads it from Cloudflare', () => {
    document.head.innerHTML = '<meta property="csp-nonce" nonce="bm9uY2Utb2YtdGhpcy1hbnN3ZXI=">'

    void loadTurnstile().catch(() => undefined)

    const script = document.head.querySelector('script')
    expect(script?.nonce).toBe('bm9uY2Utb2YtdGhpcy1hbnN3ZXI=')
    expect(script?.src).toBe(
      'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
    )
    expect(script?.async).toBe(true)
  })
})

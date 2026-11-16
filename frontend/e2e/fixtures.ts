import { test as base, expect } from '@playwright/test'
import { SITE_URL, THUMBNAIL } from './env.ts'

/** What the browser reports when the gallery's Content-Security-Policy refuses something. */
interface Policy {
  /** Each refusal so far on the gallery's own pages, as "directive blocked-address". */
  violations: string[]
}

/**
 * Playwright's test, with every media file a page loads answered here by one of the
 * fixture's thumbnails: the cards and the lightbox render, and the server is left with
 * the pages, the build's files and the API. Each test fails on an uncaught error in the
 * page, or on anything the Content-Security-Policy refuses.
 */
export const test = base.extend<{ mediaFiles: undefined; policy: Policy; pageErrors: undefined }>({
  mediaFiles: [
    async ({ page }, use) => {
      await page.route(/\/media\/(full|thumbs)\//, (route) =>
        route.fulfill({ path: THUMBNAIL, contentType: 'image/webp' }),
      )
      await use(undefined)
    },
    { auto: true },
  ],
  policy: [
    async ({ page }, use) => {
      const violations: string[] = []
      // Every frame reports, Turnstile's included, whose own policy is Cloudflare's.
      const origin = new URL(SITE_URL).origin
      await page.exposeFunction(
        'reportPolicyViolation',
        (documentUri: string, violation: string) => {
          if (new URL(documentUri).origin === origin) {
            violations.push(violation)
          }
        },
      )
      await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
          const exposed = window as unknown as {
            reportPolicyViolation: (documentUri: string, violation: string) => void
          }
          exposed.reportPolicyViolation(
            event.documentURI,
            `${event.effectiveDirective} ${event.blockedURI}`,
          )
        })
      })
      await use({ violations })
      expect(violations, 'refused by the Content-Security-Policy').toEqual([])
    },
    { auto: true },
  ],
  pageErrors: [
    async ({ page }, use) => {
      const errors: string[] = []
      page.on('pageerror', (error) => errors.push(error.message))
      await use(undefined)
      expect(errors, 'uncaught in the page').toEqual([])
    },
    { auto: true },
  ],
})

export { expect }

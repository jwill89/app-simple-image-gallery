import { test as base } from '@playwright/test'
import { THUMBNAIL } from './env.ts'

/**
 * Playwright's test, with every media file a page loads answered here by one of the
 * fixture's thumbnails: the cards and the lightbox render, and the server is left with
 * the API alone (PHP's built-in server answers one request at a time).
 */
export const test = base.extend<{ mediaFiles: undefined }>({
  mediaFiles: [
    async ({ page }, use) => {
      await page.route(/\/media\/(full|thumbs)\//, (route) =>
        route.fulfill({ path: THUMBNAIL, contentType: 'image/webp' }),
      )
      await use(undefined)
    },
    { auto: true },
  ],
})

export { expect } from '@playwright/test'

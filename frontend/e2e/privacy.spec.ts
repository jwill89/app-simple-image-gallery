import { SITE_URL } from './env.ts'
import { expect, test } from './fixtures.ts'

/**
 * Every host but the gallery's own that a page loads anything from is one the privacy
 * page names. The sign-in and sign-up pages run with Turnstile on, as in production,
 * using Cloudflare's published always-pass site key, so they load it for real (and under
 * the page's Content-Security-Policy, which the fixtures hold them to).
 */
const TURNSTILE_TEST_SITE_KEY = '1x00000000000000000000AA'

const pages = [
  '/',
  '/media/2/10',
  '/tags',
  '/tags/categories',
  '/favorites',
  '/privacy',
  '/accessibility',
]
const signInPages = ['/login', '/register']

test('a page loads from no host the privacy page does not name', async ({ page }) => {
  await page.goto('/privacy')
  await expect(page.getByRole('heading', { name: 'Privacy', exact: true })).toBeVisible()
  const named = await page.getByRole('main').innerText()

  await page.route('**/api/auth/config', async (route) => {
    const response = await route.fetch()
    const config = (await response.json()) as Record<string, unknown>
    for (const key of Object.keys(config).filter((name) => /^turnstile_?site_?key$/i.test(name))) {
      config[key] = TURNSTILE_TEST_SITE_KEY
    }
    await route.fulfill({ response, json: config })
  })

  const hosts = new Set<string>()
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.protocol.startsWith('http') && url.origin !== new URL(SITE_URL).origin) {
      hosts.add(url.host)
    }
  })

  for (const path of pages) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
  }
  for (const path of signInPages) {
    const turnstile = page.waitForRequest((request) =>
      request.url().startsWith('https://challenges.cloudflare.com/'),
    )
    await page.goto(path)
    await turnstile
    // Its script ran, and drew the check in Cloudflare's frame.
    await expect
      .poll(() =>
        page.frames().some((frame) => frame.url().startsWith('https://challenges.cloudflare.com/')),
      )
      .toBe(true)
  }

  // The last page may still be asking for its config as the test ends.
  await page.unrouteAll({ behavior: 'ignoreErrors' })

  expect([...hosts]).toContain('challenges.cloudflare.com')
  for (const host of hosts) {
    expect(named, `${host} is not named on the privacy page`).toContain(host)
  }
})

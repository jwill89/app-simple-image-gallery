import type { APIResponse } from '@playwright/test'
import { expect, test } from './fixtures.ts'

/**
 * The Content-Security-Policy the app sends is in force on every run, since the pages
 * come from the app's own routing and the fixtures fail a test on any refusal. These
 * prove the gate: the header reaches the page with a fresh nonce each time, something
 * the policy forbids is refused and reported, and a missing file is a 404, never the page.
 */

/** The policy's directives, by name. */
function directives(response: APIResponse): Map<string, string[]> {
  const policy = response.headers()['content-security-policy'] ?? ''
  return new Map(
    policy
      .split(';')
      .map((directive) => directive.trim().split(/\s+/))
      .filter((parts) => parts[0] !== '')
      .map(([name = '', ...sources]) => [name, sources]),
  )
}

/** The nonce the page's script-src admits. */
function nonceOf(response: APIResponse): string {
  const source = directives(response)
    .get('script-src')
    ?.find((value) => value.startsWith("'nonce-"))
  return source?.slice("'nonce-".length, -1) ?? ''
}

test('a page is served under an enforced policy, with no inline script or style', async ({
  request,
}) => {
  const response = await request.get('/media/1/40')
  const headers = response.headers()
  const policy = directives(response)

  expect(response.status()).toBe(200)
  expect(headers['content-security-policy-report-only']).toBeUndefined()
  expect(policy.get('default-src')).toEqual(["'self'"])
  expect(policy.get('frame-ancestors')).toEqual(["'none'"])
  expect(policy.get('script-src')).toEqual([
    "'self'",
    `'nonce-${nonceOf(response)}'`,
    'https://challenges.cloudflare.com',
  ])
  expect(policy.get('style-src')).toEqual(["'self'", `'nonce-${nonceOf(response)}'`])
  expect(headers['content-security-policy']).not.toContain('unsafe-')
  expect(headers['referrer-policy']).toBe('same-origin')
  expect(headers['x-content-type-options']).toBe('nosniff')
})

test('each answer has its own nonce, which the shell carries', async ({ request }) => {
  const answers = [await request.get('/'), await request.get('/'), await request.get('/index.html')]
  const nonces = answers.map(nonceOf)

  expect(new Set(nonces).size).toBe(3)
  for (const [i, answer] of answers.entries()) {
    const nonce = nonces[i] ?? ''
    expect(Buffer.from(nonce, 'base64')).toHaveLength(16)
    const html = await answer.text()
    expect(html).not.toContain('__CSP_NONCE__')
    expect(html).toContain(`<meta property="csp-nonce" nonce="${nonce}">`)
    const quoted = nonce.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')
    expect(html).toMatch(
      new RegExp(`<script type="module" crossorigin src="/assets/[^"]+" nonce="${quoted}">`),
    )
  }
})

test("a combobox's list draws under the policy", async ({ page }) => {
  // Reka adds a <style> element with the list; it carries the page's nonce, or the
  // fixtures fail the test on the refusal.
  await page.goto('/')
  await page.getByPlaceholder('Search tags...').fill('a')
  await expect(page.getByRole('listbox')).toBeVisible()
})

test('the page reads the nonce the header names', async ({ page }) => {
  const response = await page.goto('/tags')
  const header = response?.headers()['content-security-policy'] ?? ''

  const nonce = await page.evaluate(
    () => document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce ?? '',
  )
  expect(nonce).not.toBe('')
  expect(header).toContain(`'nonce-${nonce}'`)
})

test('an inline style is refused and reported', async ({ page, policy }) => {
  await page.goto('/tags')

  await page.evaluate(() => {
    const style = document.createElement('style')
    style.textContent = 'body { outline: 1px solid red; }'
    document.head.append(style)
  })

  await expect.poll(() => policy.violations.length).toBe(1)
  expect(policy.violations[0]).toMatch(/^style-src/)
  // The refusal is what this test expects, so it doesn't fail the test.
  policy.violations.length = 0
})

test('an inline script is refused and reported', async ({ page, policy }) => {
  await page.goto('/tags')

  await page.evaluate(() => {
    const script = document.createElement('script')
    script.textContent = 'window.planted = true'
    document.head.append(script)
  })

  await expect.poll(() => policy.violations.length).toBe(1)
  expect(policy.violations[0]).toMatch(/^script-src/)
  expect(await page.evaluate(() => 'planted' in window)).toBe(false)
  policy.violations.length = 0
})

test('a missing file is a 404, never the page', async ({ request }) => {
  for (const path of ['/assets/index-missing.js', '/api-docs/missing.css', '/favicon.ico']) {
    const response = await request.get(path)
    expect(response.status(), path).toBe(404)
    expect(await response.text(), path).not.toContain('<div id="app">')
  }
  expect((await request.get('/media/1/40/with-tags/cover.png')).status()).toBe(200)
})

test('a trailing slash answers as the path without it', async ({ request }) => {
  const response = await request.get('/tags/', { maxRedirects: 0 })

  expect(response.status()).toBe(200)
  expect(await response.text()).toContain('<title>Gallery — Tags</title>')
})

test('the API reference draws the API and calls it under its policy', async ({ page }) => {
  const response = await page.goto('/api/docs')
  const policy = response?.headers()['content-security-policy'] ?? ''

  expect(policy).toContain("default-src 'self'")
  expect(policy).not.toContain('unsafe-')
  await expect(page.getByRole('heading', { name: /Gallery API/ })).toBeVisible()

  await page.getByText('/version', { exact: true }).click()
  await page.getByRole('button', { name: 'Try it out' }).click()
  await page.getByRole('button', { name: 'Execute' }).click()
  await expect(page.locator('.live-responses-table')).toContainText(/api_?version/i)
})

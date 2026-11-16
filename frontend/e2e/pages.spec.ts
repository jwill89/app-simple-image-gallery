import type { Page } from '@playwright/test'
import { WCAG_22_AA, axeViolations } from '@jwill89/test-a11y'
import { expect, test } from './fixtures.ts'

/**
 * The main pages, each opened by its own URL in each colour scheme: it shows its heading
 * and the fixture's data, and passes axe at WCAG 2.2 AA and axe's AAA rules (enhanced
 * contrast among them). The account page is checked signed in. Everything is found by
 * role and name, never by a Bulma class, so the suite doesn't depend on the CSS framework.
 */
const pages: { path: string; heading: string; shows?: (page: Page) => Promise<void> }[] = [
  {
    path: '/',
    heading: 'Media',
    // The fixture's 30 items, on one page of 40.
    shows: (page) => expect(page.getByRole('button', { name: 'Add to favorites' })).toHaveCount(30),
  },
  {
    // A gallery page opened directly gets the SPA, not the server's shell.
    path: '/media/2/10',
    heading: 'Media',
    shows: (page) => expect(page.getByRole('button', { name: 'Add to favorites' })).toHaveCount(10),
  },
  { path: '/tags', heading: 'Tags' },
  { path: '/tags/categories', heading: 'Tag Categories' },
  { path: '/favorites', heading: 'Favorites' },
  { path: '/login', heading: 'Sign in' },
  { path: '/register', heading: 'Create an account' },
  { path: '/no-such-page', heading: '404' },
  {
    // A media item's page, whose title is for screen readers only.
    path: '/media/1/tags',
    heading: 'Media #1',
    shows: (page) =>
      expect(page.getByRole('heading', { name: 'Current Tags', exact: true })).toBeVisible(),
  },
  // A tag's implications page, titled with the tag's name.
  { path: '/tags/1', heading: 'animal' },
  { path: '/tags/danbooru-rules', heading: 'Danbooru Import Rules' },
  { path: '/privacy', heading: 'Privacy' },
  {
    // The accessibility statement, linked from every page's footer.
    path: '/accessibility',
    heading: 'Accessibility',
    shows: (page) =>
      expect(
        page.getByRole('contentinfo').getByRole('link', { name: 'Accessibility', exact: true }),
      ).toHaveAttribute('href', '/accessibility'),
  },
]

const tags = [...WCAG_22_AA, 'wcag2aaa']

/** Makes an account and signs this browser in with it. */
async function signUp(page: Page, username: string) {
  await page.goto('/register')
  await page.getByLabel('Username').fill(username)
  await page.getByLabel('Password', { exact: true }).fill('correct horse battery')
  await page.getByLabel('Password again').fill('correct horse battery')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByRole('heading', { name: 'Media', exact: true })).toBeVisible()
}

for (const colorScheme of ['light', 'dark'] as const) {
  test.describe(`${colorScheme} scheme`, () => {
    test.use({ colorScheme })

    for (const { path, heading, shows } of pages) {
      test(`${path} loads and passes axe`, async ({ page }) => {
        await page.goto(path)
        await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
        await shows?.(page)
        expect(await axeViolations(page, { tags })).toEqual([])
      })
    }

    test('/account loads signed in and passes axe', async ({ page }) => {
      const username = `e2e-${colorScheme}-${String(Date.now())}`
      await signUp(page, username)
      await page.goto('/account')
      await expect(page.getByRole('heading', { name: username, exact: true })).toBeVisible()
      await expect(page.getByRole('heading', { name: 'Passkeys', exact: true })).toBeVisible()
      expect(await axeViolations(page, { tags })).toEqual([])
    })
  })
}

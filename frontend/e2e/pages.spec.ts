import type { Page } from '@playwright/test'
import { axeViolations } from '@jwill89/test-a11y'
import { expect, test } from './fixtures.ts'

/**
 * The main pages, each opened by its own URL: it shows its heading and the fixture's
 * data, and passes axe at WCAG 2.2 AA. Everything is found by role and name, never by a
 * Bulma class, so the suite doesn't depend on the CSS framework.
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
]

for (const { path, heading, shows } of pages) {
  test(`${path} loads and passes axe`, async ({ page }) => {
    await page.goto(path)
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
    await shows?.(page)
    expect(await axeViolations(page)).toEqual([])
  })
}

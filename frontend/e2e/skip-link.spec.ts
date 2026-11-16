import { expect, test } from './fixtures.ts'

/**
 * The skip link is the first thing Tab reaches, and using it moves focus to the page's
 * one <main> without putting a hash in the address. A move to another page puts focus
 * on <main> as well, and names the page first in the title.
 */
test('the skip link moves focus to the main content and leaves the address alone', async ({
  page,
}) => {
  await page.goto('/tags')
  await expect(page.getByRole('heading', { name: 'Tags', exact: true })).toBeVisible()

  await page.keyboard.press('Tab')
  const skip = page.getByRole('link', { name: 'Skip to content' })
  await expect(skip).toBeFocused()
  await expect(skip).toBeInViewport()

  await page.keyboard.press('Enter')

  const main = page.getByRole('main')
  await expect(main).toBeFocused()
  await expect(main).toHaveAttribute('id', 'main-content')
  expect(new URL(page.url()).hash).toBe('')
})

test('the skip link stays out of sight until it has focus', async ({ page }) => {
  await page.goto('/tags')
  await expect(page.getByRole('heading', { name: 'Tags', exact: true })).toBeVisible()

  await expect(page.getByRole('link', { name: 'Skip to content' })).not.toBeInViewport()
})

test('a new page puts focus on the main content and its name first in the title', async ({
  page,
}) => {
  await page.goto('/tags')
  await expect(page).toHaveTitle('Tags - Gallery')

  await page.getByRole('link', { name: 'Favorites' }).click()

  await expect(page.getByRole('heading', { name: 'Favorites', exact: true })).toBeVisible()
  await expect(page.getByRole('main')).toBeFocused()
  await expect(page).toHaveTitle('Favorites - Gallery')
})

test('the navigation marks the current page', async ({ page }) => {
  await page.goto('/tags')

  const nav = page.getByRole('navigation', { name: 'main-menu' })
  await expect(nav.getByRole('link', { name: 'Tags' })).toHaveAttribute('aria-current', 'page')
  await expect(nav.getByRole('link', { name: 'Favorites' })).not.toHaveAttribute('aria-current')
})

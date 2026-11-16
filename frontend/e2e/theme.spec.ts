// The page-side code below runs in the browser, against its DOM.
/// <reference lib="dom" />
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures.ts'

/**
 * The theme follows the device until the reader picks one from the navbar, and the pick
 * lasts across a reload; "Match device" goes back to following it.
 */

/** The page's ground colour as the browser paints it. */
const ground = (page: Page) => page.evaluate(() => getComputedStyle(document.body).backgroundColor)

const LIGHT_GROUND = 'rgb(255, 255, 255)'
const DARK_GROUND = 'rgb(19, 20, 23)'

async function pick(page: Page, name: string) {
  await page.getByRole('button', { name: /^Theme:/ }).click()
  await page.getByRole('menuitemradio', { name }).click()
}

test.use({ colorScheme: 'dark' })

test('follows the device until a scheme is picked, and keeps the pick', async ({ page }) => {
  await page.goto('/tags')
  await expect(page.getByRole('heading', { name: 'Tags', exact: true })).toBeVisible()
  expect(await ground(page)).toBe(DARK_GROUND)
  await expect(page.getByRole('button', { name: 'Theme: Match device' })).toBeVisible()

  await pick(page, 'Light')

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  expect(await ground(page)).toBe(LIGHT_GROUND)

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Tags', exact: true })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  expect(await ground(page)).toBe(LIGHT_GROUND)

  await page.getByRole('button', { name: 'Theme: Light' }).click()
  await expect(page.getByRole('menuitemradio', { name: 'Light' })).toHaveAttribute(
    'aria-checked',
    'true',
  )
  await page.getByRole('menuitemradio', { name: 'Match device' }).click()

  await expect(page.locator('html')).not.toHaveAttribute('data-theme')
  expect(await ground(page)).toBe(DARK_GROUND)
})

test('works from the keyboard', async ({ page }) => {
  await page.goto('/tags')
  const trigger = page.getByRole('button', { name: /^Theme:/ })
  await trigger.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('menuitemradio', { name: 'Match device' })).toBeVisible()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(trigger).toBeFocused()
})

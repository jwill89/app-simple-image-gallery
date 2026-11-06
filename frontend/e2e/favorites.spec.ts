import { expect, test } from './fixtures.ts'

/**
 * The key flow: sign up, heart an item, and find it in the account's favorites after a
 * full reload. It needs the session cookie, both origin checks and the account's list to
 * work end to end.
 */
test('a new account keeps its favorites', async ({ page }) => {
  await page.goto('/register')
  await page.getByLabel('Username').fill(`e2e-${String(Date.now())}`)
  await page.getByLabel('Password', { exact: true }).fill('correct horse battery')
  await page.getByLabel('Password again').fill('correct horse battery')
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByRole('heading', { name: 'Media', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Add to favorites' }).first().click()
  await expect(page.getByRole('button', { name: 'Remove from favorites' })).toHaveCount(1)

  // A full load: the browser keeps only its session cookie, so the list comes from the server.
  await page.goto('/favorites')
  await expect(page.getByRole('button', { name: 'Remove from favorites' })).toHaveCount(1)
})

import { expect, test } from '@playwright/test';
import { testUser } from './fixtures';

/**
 * Settings is reachable only through the user menu, and an unmatched route in
 * React Router renders *nothing* rather than erroring — so a stale link there
 * shows a blank screen with no clue why. These walk the real path a person
 * takes, which is the only way that failure is visible.
 */
test('the user menu opens settings, which lands on Password', async ({ page }) => {
    await page.goto('/inbox');

    await page.getByRole('button', { name: new RegExp(testUser.name) }).click();
    await page.getByRole('menuitem', { name: 'Settings' }).click();

    // /settings redirects to whichever page settings currently opens on.
    await page.waitForURL('**/settings/password');

    await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();
});

test('every settings page renders from its own nav', async ({ page }) => {
    await page.goto('/settings');
    await page.waitForURL('**/settings/password');

    const nav = page.getByRole('navigation').filter({ hasText: 'Appearance' });

    await nav.getByRole('link', { name: 'Appearance' }).click();
    await page.waitForURL('**/settings/appearance');
    await expect(page.getByRole('heading', { name: 'Appearance settings' })).toBeVisible();

    await nav.getByRole('link', { name: 'Language' }).click();
    await page.waitForURL('**/settings/language');
    await expect(page.getByRole('heading', { name: 'Language', exact: true })).toBeVisible();

    await nav.getByRole('link', { name: 'Password' }).click();
    await page.waitForURL('**/settings/password');
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();
});

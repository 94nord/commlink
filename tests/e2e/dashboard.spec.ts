import { expect, test } from '@playwright/test';
import { testUser } from './fixtures';

test('an authenticated user sees their corporation on the dashboard', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByRole('heading', { name: testUser.corporation })).toBeVisible();
});

test.describe('as a guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('the dashboard redirects to the login page', async ({ page }) => {
        await page.goto('/dashboard');

        await page.waitForURL('/login');
        await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    });

    test('the registration page does not exist', async ({ page }) => {
        const response = await page.goto('/register');

        expect(response?.status()).toBe(404);
    });
});

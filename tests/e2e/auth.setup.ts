import { expect, test as setup } from '@playwright/test';
import { resetDatabase, seedUser, testUser } from './fixtures';

const authFile = 'tests/e2e/.auth/user.json';

// Log in once through the real form; every test reuses the stored session.
setup('authenticate', async ({ page, request }) => {
    await resetDatabase(request);
    await seedUser(request);

    // Pin the whole suite to English — storageState() below snapshots
    // localStorage, so every other e2e test reuses this without setting it.
    await page.addInitScript(() => localStorage.setItem('language', 'en'));

    await page.goto('/login');
    await page.getByLabel('Email address').fill(testUser.email);
    await page.getByLabel('Password', { exact: true }).fill(testUser.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    // The inbox is the landing page now that the dashboard is gone.
    await page.waitForURL('/inbox');
    await expect(page.getByRole('heading', { name: 'Inbox', level: 1 })).toBeVisible();

    await page.context().storageState({ path: authFile });
});

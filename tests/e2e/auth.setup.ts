import { expect, test as setup } from '@playwright/test';
import { resetDatabase, seedUser, testUser } from './fixtures';

const authFile = 'tests/e2e/.auth/user.json';

// Log in once through the real form; every test reuses the stored session.
setup('authenticate', async ({ page, request }) => {
    await resetDatabase(request);
    await seedUser(request);

    await page.goto('/login');
    await page.getByLabel('Email address').fill(testUser.email);
    await page.getByLabel('Password', { exact: true }).fill(testUser.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await page.waitForURL('/dashboard');
    await expect(page.getByRole('heading', { name: testUser.corporation })).toBeVisible();

    await page.context().storageState({ path: authFile });
});

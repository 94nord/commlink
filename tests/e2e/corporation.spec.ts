import { expect, test } from '@playwright/test';
import { testUser } from './fixtures';

// A tiny 1x1 PNG.
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'base64',
);

test('an admin sees the fixed corporation name and uploads a logo', async ({ page }) => {
    await page.goto('/corporation');
    await expect(page.getByRole('heading', { name: 'Corporation' })).toBeVisible();

    // The name is shown, but there is nothing to edit it with.
    await expect(page.getByText(testUser.corporation)).toBeVisible();
    await expect(page.getByLabel('Corporation name')).not.toBeVisible();

    // Upload a logo — the hidden file input backs the "Upload logo" button
    await page.setInputFiles('input[type=file]', { name: 'logo.png', mimeType: 'image/png', buffer: PNG });
    await expect(page.getByRole('img', { name: 'Corporation logo' })).toBeVisible();

    // It persists
    await page.reload();
    await expect(page.getByRole('img', { name: 'Corporation logo' })).toBeVisible();

    // And can be removed
    await page.getByRole('button', { name: 'Remove' }).click();
    await expect(page.getByRole('img', { name: 'Corporation logo' })).not.toBeVisible();
});

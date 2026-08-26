import { expect, test } from '@playwright/test';

test('erasing a customer scrubs their PII', async ({ page }) => {
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Erase Person');
    await page.getByLabel('Email').fill('erase@example.com');
    await page.getByLabel('Phone').fill('20 12 34 56');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Erase Person')).toBeVisible();
    // Stored canonically, the same way an inbox address is.
    await expect(page.getByText('+4520123456')).toBeVisible();

    await page.getByRole('link', { name: /Erase Person/ }).click();
    await expect(page.getByRole('heading', { name: 'Erase Person' })).toBeVisible();

    await page.getByRole('button', { name: 'Erase customer' }).click();
    await expect(page.getByRole('heading', { name: 'Erase customer?' })).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Erase customer' }).click();

    // Lands on the customer list; the person is gone
    await page.waitForURL('/customers');
    await expect(page.getByText('Erase Person')).not.toBeVisible();
    await expect(page.getByText('+4520123456')).not.toBeVisible();
});

test('removing a phone number asks first', async ({ page }) => {
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Number Owner');
    await page.getByLabel('Phone').fill('31 23 45 67');
    await page.getByRole('button', { name: 'Create customer' }).click();

    await page.getByRole('link', { name: /Number Owner/ }).click();
    await expect(page.getByText('+4531234567')).toBeVisible();

    await page.getByRole('button', { name: 'Remove' }).click();

    // Nothing was ever sent on it, so it just goes — and the dialog says so.
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Remove +4531234567?' })).toBeVisible();
    await expect(dialog.getByText('simply removed')).toBeVisible();

    await dialog.getByRole('button', { name: 'Remove number' }).click();

    await expect(page.getByText('No numbers yet')).toBeVisible();
});

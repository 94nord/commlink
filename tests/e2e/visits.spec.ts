import { expect, test } from '@playwright/test';

test('an admin schedules, moves, and cancels visits with double-booking blocked', async ({ page }) => {
    // Setup: a customer and a standalone case
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Visit Person');
    await page.getByLabel('Email address').fill('visit@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Visit Person')).toBeVisible();

    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Visit Person/ }).click();
    await page.getByLabel('Address').fill('Visit Street 1');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByText('Visit Street 1')).toBeVisible();

    // Open the case detail page
    await page.getByRole('link', { name: /Visit Street 1/ }).click();
    await expect(page.getByRole('heading', { name: 'Visit Street 1' })).toBeVisible();
    await expect(page.getByText('No visits yet.')).toBeVisible();

    // The visit rows list (scoped: the sidebar is also ul/li, and open
    // dialogs contain the same words as the badges).
    const rows = page.locator('ul.divide-y > li');

    // Create a scheduled visit with the founder staff member
    await page.getByRole('button', { name: 'New visit' }).click();
    await page.getByLabel('Type').click();
    await page.getByRole('option', { name: 'Survey' }).click();
    await page.getByLabel('Staff').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('From').fill('2026-08-03T09:00');
    await page.getByLabel('To').fill('2026-08-03T10:00');
    await page.getByRole('button', { name: 'Create visit' }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(rows.filter({ hasText: 'Survey' })).toContainText('09:00 – 10:00');

    // A second visit overlapping the same staff time is rejected cleanly
    await page.getByRole('button', { name: 'New visit' }).click();
    await page.getByLabel('Type').click();
    await page.getByRole('option', { name: 'Handover' }).click();
    await page.getByLabel('Staff').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('From').fill('2026-08-03T09:30');
    await page.getByLabel('To').fill('2026-08-03T10:30');
    await page.getByRole('button', { name: 'Create visit' }).click();

    await expect(page.getByText('This staff member is already booked in that time.')).toBeVisible();

    // Pick a free time instead
    await page.getByLabel('From').fill('2026-08-03T10:00');
    await page.getByLabel('To').fill('2026-08-03T11:00');
    await page.getByRole('button', { name: 'Create visit' }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(rows.filter({ hasText: 'Handover' })).toContainText('10:00 – 11:00');

    // Move the survey visit via the pencil
    await rows.filter({ hasText: 'Survey' }).getByRole('button', { name: 'Edit visit' }).click();
    await page.getByLabel('From').fill('2026-08-04T13:00');
    await page.getByLabel('To').fill('2026-08-04T14:00');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(rows.filter({ hasText: 'Survey' })).toContainText('13:00 – 14:00');

    // Cancel the survey visit
    await rows.filter({ hasText: 'Survey' }).getByRole('button', { name: 'Cancel visit' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Yes, cancel it' }).click();

    await expect(rows.filter({ hasText: 'Survey' })).toContainText('Cancelled');
});

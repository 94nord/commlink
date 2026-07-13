import { expect, test } from '@playwright/test';

test('visits index lists visits and supports create, move, and delete', async ({ page }) => {
    // Create a customer and case to attach visits to
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Index Person');
    await page.getByLabel('Email address').fill('index@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Index Person')).toBeVisible();

    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Index Person/ }).click();
    await page.getByLabel('Address').fill('Index Street 99');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByText('Index Street 99')).toBeVisible();

    // Navigate to visits index via the sidebar
    await page.goto('/visits');
    await expect(page.getByRole('heading', { name: 'Visits' })).toBeVisible();

    // Upcoming tab is active by default
    const upcomingTab = page.getByRole('button', { name: /upcoming/i });
    const previousTab = page.getByRole('button', { name: /previous/i });
    await expect(upcomingTab).toBeVisible();
    await expect(previousTab).toBeVisible();

    const rows = page.locator('ul.divide-y > li');

    // Create a future visit from the visits index
    await page.getByRole('button', { name: 'New visit' }).click();
    await page.getByLabel('Case').click();
    await page.getByRole('option', { name: /Index Street 99/ }).click();
    await page.getByLabel('Type').click();
    await page.getByRole('option', { name: 'Handover' }).click();
    await page.getByLabel('Staff').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('From').fill('2026-09-01T10:00');
    await page.getByLabel('To').fill('2026-09-01T11:00');
    await page.getByRole('button', { name: 'Create visit' }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(rows.filter({ hasText: 'Handover' })).toContainText('10:00 – 11:00');
    await expect(rows.filter({ hasText: 'Handover' })).toContainText('Index Street 99');

    // Move the visit (pencil only visible on upcoming)
    await rows.filter({ hasText: 'Handover' }).getByRole('button', { name: 'Edit visit' }).click();
    await page.getByLabel('From').fill('2026-09-02T14:00');
    await page.getByLabel('To').fill('2026-09-02T15:00');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(rows.filter({ hasText: 'Handover' })).toContainText('14:00 – 15:00');

    // The range links to the visit detail page with its linked entities
    await rows.filter({ hasText: 'Handover' }).getByRole('link', { name: /14:00 – 15:00/ }).click();
    await expect(page.getByRole('heading', { name: 'Handover visit' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Index Street 99' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Index Person' })).toBeVisible();

    // Edit is available from the detail page too
    await page.getByRole('button', { name: 'Edit visit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    await page.goto('/visits');

    // Delete the visit
    await rows.filter({ hasText: 'Handover' }).getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();

    await expect(rows.filter({ hasText: 'Handover' })).not.toBeVisible();
});

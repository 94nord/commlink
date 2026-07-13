import { expect, test } from '@playwright/test';

const pad = (n: number) => String(n).padStart(2, '0');

// Always next week's Monday, so it is exactly one "Next" click away
// regardless of which weekday the test runs on.
function nextMonday(): Date {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + (((1 - d.getDay()) + 7) % 7 || 7));
    return d;
}

function isoDate(d: Date) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

test('the dashboard week calendar creates, moves, and cancels visits', async ({ page }) => {
    // A customer and case to schedule against (unique names — the DB persists
    // across specs in a run).
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Schedule Person');
    await page.getByLabel('Email address').fill('schedule@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Schedule Person')).toBeVisible();

    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Schedule Person/ }).click();
    await page.getByLabel('Address').fill('Schedule Street 7');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByText('Schedule Street 7')).toBeVisible();

    const monday = nextMonday();
    const tuesday = new Date(monday);
    tuesday.setDate(tuesday.getDate() + 1);

    await page.goto('/dashboard');
    await page.getByRole('button', { name: 'Next' }).click();

    // Clicking an empty slot opens the new-visit dialog prefilled with it
    await page.getByRole('button', { name: `New visit ${isoDate(monday)} 10:00` }).click();
    await expect(page.getByLabel('From')).toHaveValue(`${isoDate(monday)}T10:00`);
    await expect(page.getByLabel('To')).toHaveValue(`${isoDate(monday)}T11:00`);

    await page.getByLabel('Case').click();
    await page.getByRole('option', { name: /Schedule Street 7/ }).click();
    await page.getByLabel('Type').click();
    await page.getByRole('option', { name: 'Survey' }).click();
    await page.getByLabel('Staff').click();
    await page.getByRole('option').first().click();
    await page.getByRole('button', { name: 'Create visit' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    // The visit shows as a block on the grid
    const block = page.getByRole('button', { name: /^Visit Schedule Street 7/ });
    await expect(block).toBeVisible();
    await expect(block).toContainText('10:00 – 11:00');
    await expect(block).toContainText('Schedule Person');

    // Clicking the block opens the move dialog; move it to Tuesday 14:00
    await block.click();
    await page.getByLabel('From').fill(`${isoDate(tuesday)}T14:00`);
    await page.getByLabel('To').fill(`${isoDate(tuesday)}T15:00`);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    const moved = page.getByRole('button', { name: `Visit Schedule Street 7 14:00 – 15:00` });
    await expect(moved).toBeVisible();

    // Cancel it from the move dialog — the block disappears
    await moved.click();
    await page.getByRole('button', { name: 'Cancel visit' }).click();
    await page.getByRole('button', { name: 'Yes, cancel it' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByRole('button', { name: /^Visit Schedule Street 7/ })).not.toBeVisible();
});

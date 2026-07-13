import { expect, test } from '@playwright/test';

function isoDate(daysAhead: number) {
    const d = new Date();
    d.setDate(d.getDate() + daysAhead);
    return d.toISOString().slice(0, 10);
}

test('a customer books and reschedules through their magic link', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);

    // Setup: a customer and a case
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Booking Person');
    await page.getByLabel('Email address').fill('booking@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Booking Person')).toBeVisible();

    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Booking Person/ }).click();
    await page.getByLabel('Address').fill('Booking Street 5');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByText('Booking Street 5')).toBeVisible();

    // A round with a window and bookable hours
    await page.goto('/rounds');
    await page.getByRole('button', { name: 'New round' }).click();
    await page.getByLabel('Visit type').click();
    await page.getByRole('option', { name: 'Survey' }).click();
    await page.getByLabel('Window from', { exact: false }).fill(isoDate(1));
    await page.getByLabel('Window to').fill(isoDate(3));
    await page.getByLabel('Close-off date').fill(isoDate(10));
    await page.getByRole('button', { name: 'Create round' }).click();
    await expect(page.getByRole('heading', { name: 'Survey round' })).toBeVisible();

    // Add the case and open the round
    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByRole('dialog').getByText('Booking Street 5').click();
    await page.getByRole('button', { name: /Add 1 case/ }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    await page.getByRole('button', { name: 'Open round' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Open round' }).click();
    await expect(page.getByText('open', { exact: true })).toBeVisible();
    await expect(page.getByText('Not booked')).toBeVisible();

    // Copy the case's booking link
    await page.getByRole('button', { name: 'Copy booking link' }).click();
    const bookingUrl = await page.evaluate(() => navigator.clipboard.readText());
    expect(bookingUrl).toContain('/book/');

    // The customer books the first offered slot
    await page.goto(bookingUrl);
    await expect(page.getByRole('heading', { name: /Survey visit · Booking Street 5/ })).toBeVisible();

    const slotButtons = page.locator('section button');
    const firstSlot = (await slotButtons.first().textContent())!.trim();
    await slotButtons.first().click();
    await page.getByRole('button', { name: new RegExp(`^Book ${firstSlot}`) }).click();

    await expect(page.getByText('Your booked time')).toBeVisible();
    await expect(page.getByText('Booked', { exact: true })).toBeVisible();

    // Reschedule to another slot from the same link
    const nextSlot = (await slotButtons.first().textContent())!.trim();
    await slotButtons.first().click();
    await page.getByRole('button', { name: new RegExp(`^Reschedule ${nextSlot}`) }).click();
    await expect(page.getByText('Your booked time')).toBeVisible();

    // The admin sees the case as booked
    await page.goto('/rounds');
    await page.getByRole('link', { name: /Survey round/ }).click();
    await expect(page.getByText(/Booked ·/)).toBeVisible();
});

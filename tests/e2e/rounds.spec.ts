import { expect, test } from '@playwright/test';

function isoDate(daysAhead: number) {
    const d = new Date();
    d.setDate(d.getDate() + daysAhead);
    return d.toISOString().slice(0, 10);
}

test('an admin creates a round, populates it from a project, opens and closes it', async ({ page }) => {
    // Setup: a customer, a project, and two cases in the project
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Round Person');
    await page.getByLabel('Email address').fill('round@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Round Person')).toBeVisible();

    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Round Building');
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page.getByRole('heading', { name: 'Round Building' })).toBeVisible();

    // Cases are created on the Cases page and filed under the project there
    for (const address of ['Round Street 1', 'Round Street 2']) {
        await page.goto('/cases');
        await page.getByRole('button', { name: 'New case' }).click();
        await page.getByLabel('Customer').click();
        await page.getByRole('option', { name: /Round Person/ }).click();
        await page.getByLabel('Address', { exact: true }).fill(address);
        await page.getByLabel(/^Project/).click();
        await page.getByRole('option', { name: 'Round Building' }).click();
        await page.getByRole('button', { name: 'Create case' }).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.getByText(address)).toBeVisible();
    }

    // Create a draft round filed under the project
    await page.goto('/rounds');
    await expect(page.getByRole('heading', { name: 'Rounds' })).toBeVisible();

    await page.getByRole('button', { name: 'New round' }).click();
    await page.getByLabel('Visit type').click();
    await page.getByRole('option', { name: 'Handover' }).click();
    // Opening requires a window (slots need bounded days).
    await page.getByLabel('Window from', { exact: false }).fill(isoDate(1));
    await page.getByLabel('Window to').fill(isoDate(5));
    await page.getByLabel('Close-off date').fill(isoDate(15));
    // A scheduled start — the manual open below overrides it.
    await page.getByLabel('Scheduled start', { exact: false }).fill(`${isoDate(1)}T09:00`);
    await page.getByLabel(/^Project/).click();
    await page.getByRole('option', { name: 'Round Building' }).click();
    await page.getByRole('button', { name: 'Create round' }).click();

    // Lands on the round page as a scheduled draft
    await expect(page.getByRole('heading', { name: 'Handover round' })).toBeVisible();
    // Badge text is lowercase in the DOM (capitalized via CSS only).
    await expect(page.getByText('draft', { exact: true })).toBeVisible();
    await expect(page.getByText(/^Opens /)).toBeVisible();
    await expect(page.getByText('No cases in this round yet')).toBeVisible();

    // Add one case individually while still a draft
    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByRole('dialog').getByText('Round Street 1').click();
    await page.getByRole('button', { name: /Add 1 case/ }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByText('Round Street 1')).toBeVisible();

    // Open the round manually — this overrides the scheduled start
    await page.getByRole('button', { name: 'Open round' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Open round' }).click();
    await expect(page.getByText('open', { exact: true })).toBeVisible();
    await expect(page.getByText(/^Opens /)).not.toBeVisible();

    // Late-add after the round has begun: populate picks up the remaining
    // project case and skips the one already in the round
    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByLabel('Populate from project').click();
    await page.getByRole('option', { name: 'Round Building' }).click();
    await page.getByRole('button', { name: /Add 1 case/ }).click();

    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByText('Round Street 2')).toBeVisible();

    // Close the round
    await page.getByRole('button', { name: 'Close round' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Close round' }).click();
    await expect(page.getByText('closed', { exact: true })).toBeVisible();

    // The round lists on its project page
    await page.goto('/projects');
    await page.getByRole('link', { name: /Round Building/ }).click();
    await expect(page.getByText('Handover round · closes')).toBeVisible();
});

import { expect, test, type Page } from '@playwright/test';

async function createCase(page: Page, opts: { customer: RegExp; address: string; title?: string }) {
    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: opts.customer }).click();
    if (opts.title) await page.getByLabel('Title', { exact: false }).fill(opts.title);
    await page.getByLabel('Address', { exact: true }).fill(opts.address);
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
}

test('an admin creates cases, then attaches existing ones to a project', async ({ page }) => {
    // Customers — one carries a default address to prove case-address prefill
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Anna Larsen');
    await page.getByLabel('Email address').fill('anna@example.com');
    await page.getByLabel(/^Address/).fill('Harbour Street 1, 1. th');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Anna Larsen')).toBeVisible();

    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Bo Jensen');
    await page.getByLabel('Email address').fill('bo@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Bo Jensen')).toBeVisible();

    // New-case dialog prefills the address from the picked customer
    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Anna Larsen/ }).click();
    await expect(page.getByLabel('Address', { exact: true })).toHaveValue('Harbour Street 1, 1. th');
    await page.getByLabel('Title', { exact: false }).fill('Apartment 1');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    await createCase(page, { customer: /Bo Jensen/, address: 'Harbour Street 1, 2. tv' });

    // Title shows as the primary label in the cases list
    await expect(page.getByText('Apartment 1')).toBeVisible();

    // Create a project and attach both existing (standalone) cases
    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Harbour Block A');
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page.getByRole('heading', { name: 'Harbour Block A' })).toBeVisible();

    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByRole('dialog').getByText('Apartment 1').click();
    await page.getByRole('dialog').getByText('Harbour Street 1, 2. tv').click();
    await page.getByRole('button', { name: /Add 2 cases/ }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    await expect(page.getByText('Apartment 1')).toBeVisible();
    await expect(page.getByText('Harbour Street 1, 2. tv')).toBeVisible();

    // Project list shows the count, labelled "cases"
    await page.goto('/projects');
    await expect(page.getByRole('link', { name: /Harbour Block A/ })).toContainText('2 cases');
});

test('an admin can edit a project name', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Old Name');
    await page.getByRole('button', { name: 'Create project' }).click();

    await expect(page.getByRole('heading', { name: 'Old Name' })).toBeVisible();

    await page.getByRole('button', { name: 'Edit project' }).click();
    await page.getByLabel('Project name').clear();
    await page.getByLabel('Project name').fill('New Name');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('heading', { name: 'New Name' })).toBeVisible();
});

test('an admin can remove a case from a project without deleting it', async ({ page }) => {
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Test Person');
    await page.getByLabel('Email address').fill('test@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    // Wait for the create to land before navigating away in createCase.
    await expect(page.getByText('Test Person')).toBeVisible();

    await createCase(page, { customer: /Test Person/, address: 'Main Street 1' });

    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Detach Project');
    await page.getByRole('button', { name: 'Create project' }).click();

    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByRole('dialog').getByText('Main Street 1').click();
    await page.getByRole('button', { name: /Add 1 case/ }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByText('Main Street 1')).toBeVisible();

    // Remove from project (detach) — the case itself survives
    await page.getByRole('button', { name: 'Remove from project' }).click();
    await expect(page.getByRole('heading', { name: 'Remove case from project?' })).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Remove case' }).click();
    await expect(page.getByText('No cases in this project yet.')).toBeVisible();

    // Still present as a standalone case
    await page.goto('/cases');
    await expect(page.getByText('Main Street 1')).toBeVisible();
});

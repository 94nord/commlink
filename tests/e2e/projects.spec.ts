import { expect, test } from '@playwright/test';

test('an admin creates customers, a project, and registers cases', async ({ page }) => {
    // Create customers first
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'Customers' })).toBeVisible();

    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Anna Larsen');
    await page.getByLabel('Email address').fill('anna@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();

    await expect(page.getByText('Anna Larsen')).toBeVisible();

    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Bo Jensen');
    await page.getByLabel('Email address').fill('bo@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();

    await expect(page.getByText('Bo Jensen')).toBeVisible();

    // Create a project
    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Harbour Block A');
    await page.getByRole('button', { name: 'Create project' }).click();

    await expect(page.getByRole('heading', { name: 'Harbour Block A' })).toBeVisible();
    await expect(page.getByText('No cases registered yet.')).toBeVisible();

    // Add first case
    await page.getByRole('button', { name: 'Add case' }).click();
    await page.getByRole('combobox').click();
    await page.getByRole('option', { name: /Anna Larsen/ }).click();
    await page.getByLabel('Address').fill('Harbour Street 1, 1. th');
    await page.getByRole('button', { name: 'Add case' }).last().click();

    await expect(page.getByText('Harbour Street 1, 1. th')).toBeVisible();

    // Add second case
    await page.getByRole('button', { name: 'Add case' }).click();
    await page.getByRole('combobox').click();
    await page.getByRole('option', { name: /Bo Jensen/ }).click();
    await page.getByLabel('Address').fill('Harbour Street 1, 2. tv');
    await page.getByRole('button', { name: 'Add case' }).last().click();

    await expect(page.getByText('Harbour Street 1, 1. th')).toBeVisible();
    await expect(page.getByText('Harbour Street 1, 2. tv')).toBeVisible();
    await expect(page.getByText('Anna Larsen · anna@example.com')).toBeVisible();
    await expect(page.getByText('Bo Jensen · bo@example.com')).toBeVisible();

    // Verify case count on projects list
    await page.goto('/projects');
    await expect(page.getByRole('link', { name: /Harbour Block A/ })).toContainText('2 apartments');

    // Cases page shows both cases
    await page.goto('/cases');
    await expect(page.getByRole('heading', { name: 'Cases' })).toBeVisible();
    await expect(page.getByText('Harbour Street 1, 1. th')).toBeVisible();
    await expect(page.getByText('Harbour Street 1, 2. tv')).toBeVisible();
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

test('an admin can delete a case from a project', async ({ page }) => {
    // Setup: customer + project + case
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Test Person');
    await page.getByLabel('Email address').fill('test@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();

    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('Delete Test Project');
    await page.getByRole('button', { name: 'Create project' }).click();

    await page.getByRole('button', { name: 'Add case' }).click();
    await page.getByRole('combobox').click();
    await page.getByRole('option', { name: /Test Person/ }).click();
    await page.getByLabel('Address').fill('Main Street 1');
    await page.getByRole('button', { name: 'Add case' }).last().click();

    await expect(page.getByText('Main Street 1')).toBeVisible();

    // Delete the case: row trash button opens the confirm dialog, then confirm
    await page.getByRole('button', { name: 'Delete case' }).click();
    await expect(page.getByRole('heading', { name: 'Delete case?' })).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete case' }).click();

    await expect(page.getByText('No cases registered yet.')).toBeVisible();
});

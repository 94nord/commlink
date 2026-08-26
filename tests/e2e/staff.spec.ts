import { expect, test } from '@playwright/test';

const MAILPIT = `http://localhost:${process.env.TEST_MAILPIT_PORT ?? 9025}`;

test('the founder is provisioned as staff and staff can be managed', async ({ page }) => {
    await page.goto('/staff');
    await expect(page.getByRole('heading', { name: 'Staff' })).toBeVisible();

    // Scope to the content list — the sidebar nav is also built from ul/li.
    const rows = page.locator('ul.divide-y > li');

    // The founder staff member exists (provisioned by /_test/seed).
    await expect(rows).toHaveCount(1);

    // Add a second staff member
    await page.getByRole('button', { name: 'New staff member' }).click();
    await page.getByLabel('Name').fill('Second Inspector');
    await page.getByRole('button', { name: 'Create staff member' }).click();

    await expect(page.getByText('Second Inspector')).toBeVisible();
    await expect(rows).toHaveCount(2);

    // Rename them
    await rows.filter({ hasText: 'Second Inspector' }).getByRole('button', { name: 'Edit staff member' }).click();
    await page.getByLabel('Name').clear();
    await page.getByLabel('Name').fill('Renamed Inspector');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByText('Renamed Inspector')).toBeVisible();

    // Delete them: row trash button opens the confirm dialog, then confirm
    await rows.filter({ hasText: 'Renamed Inspector' }).getByRole('button', { name: 'Delete staff member' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete staff member' }).click();

    await expect(page.getByText('Renamed Inspector')).not.toBeVisible();
    await expect(rows).toHaveCount(1);

    // The founder — the only staff member left — cannot be deleted. This is
    // the founder guard specifically, not the more general "last staff
    // member" one: the button is already disabled, so the dialog never
    // opens to begin with.
    await expect(rows.getByRole('button', { name: 'Delete staff member' })).toBeDisabled();
    await expect(rows).toHaveCount(1);
});

test('an admin invites a staff member to log in', async ({ page, request }) => {
    await request.delete(`${MAILPIT}/api/v1/messages`);

    await page.goto('/staff');
    const rows = page.locator('ul.divide-y > li');

    // Add a staff member with an email and the admin role
    await page.getByRole('button', { name: 'New staff member' }).click();
    await page.getByLabel('Name').fill('Casey Inspector');
    await page.getByLabel(/Email/).fill('casey@e2e.test');
    await page.getByRole('checkbox').click();
    await page.getByRole('button', { name: 'Create staff member' }).click();

    const row = rows.filter({ hasText: 'Casey Inspector' });
    await expect(row).toContainText('Admin');

    // Invite them — a set-password email lands in Mailpit
    await row.getByRole('button', { name: 'Invite to log in' }).click();
    await expect(row.getByRole('button', { name: 'Resend invite' })).toBeVisible();

    await expect.poll(async () => {
        const list = await (await request.get(`${MAILPIT}/api/v1/messages`)).json();
        return list.messages.filter((m: { To: { Address: string }[] }) => m.To[0].Address === 'casey@e2e.test').length;
    }).toBeGreaterThan(0);
});

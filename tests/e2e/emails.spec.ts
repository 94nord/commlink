import { expect, test } from '@playwright/test';

const MAILPIT = `http://localhost:${process.env.TEST_MAILPIT_PORT ?? 9025}`;

function isoDate(daysAhead: number) {
    const d = new Date();
    d.setDate(d.getDate() + daysAhead);
    return d.toISOString().slice(0, 10);
}

test('opening a round emails the invite, and booking emails a confirmation', async ({ page, request }) => {
    // Three synchronous outbox drains plus two full UI journeys — give this
    // test triple the default timeout.
    test.slow();

    // Flush anything earlier specs queued, then start with an empty mailbox
    await request.post('/_test/drain-outbox');
    await request.delete(`${MAILPIT}/api/v1/messages`);

    // A corporation logo so the outgoing mail carries it in the footer
    const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'base64',
    );
    await page.goto('/corporation');
    await page.setInputFiles('input[type=file]', { name: 'logo.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByRole('img', { name: 'Corporation logo' })).toBeVisible();

    // Setup: customer + case
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel('Name').fill('Mail Person');
    await page.getByLabel('Email address').fill('mail@example.com');
    await page.getByRole('button', { name: 'Create customer' }).click();
    await expect(page.getByText('Mail Person')).toBeVisible();

    await page.goto('/cases');
    await page.getByRole('button', { name: 'New case' }).click();
    await page.getByLabel('Customer').click();
    await page.getByRole('option', { name: /Mail Person/ }).click();
    await page.getByLabel('Address').fill('Mail Street 7');
    await page.getByRole('button', { name: 'Create case' }).click();
    await expect(page.getByText('Mail Street 7')).toBeVisible();

    // Round with a window, one case, opened
    await page.goto('/rounds');
    await page.getByRole('button', { name: 'New round' }).click();
    await page.getByLabel('Visit type').click();
    await page.getByRole('option', { name: 'Survey' }).click();
    await page.getByLabel('Window from', { exact: false }).fill(isoDate(1));
    await page.getByLabel('Window to').fill(isoDate(3));
    await page.getByLabel('Close-off date').fill(isoDate(10));
    await page.getByRole('button', { name: 'Create round' }).click();
    await expect(page.getByRole('heading', { name: 'Survey round' })).toBeVisible();

    await page.getByRole('button', { name: 'Add cases' }).click();
    await page.getByRole('dialog').getByText('Mail Street 7').click();
    await page.getByRole('button', { name: /Add 1 case/ }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();

    // Confirm-and-preview: opening announces the sendout size (SO3)
    await page.getByRole('button', { name: 'Open round' }).click();
    await expect(page.getByRole('dialog')).toContainText('This will email 1 customer');
    await page.getByRole('dialog').getByRole('button', { name: 'Open round' }).click();
    await expect(page.getByText('open', { exact: true })).toBeVisible();

    // Drain the outbox and read the invite from Mailpit
    await request.post('/_test/drain-outbox');

    const list = await (await request.get(`${MAILPIT}/api/v1/messages`)).json();
    expect(list.total).toBe(1);
    expect(list.messages[0].To[0].Address).toBe('mail@example.com');
    expect(list.messages[0].Subject).toContain('Book your survey visit');

    const detail = await (await request.get(`${MAILPIT}/api/v1/message/${list.messages[0].ID}`)).json();
    const bookingUrl = (detail.Text as string).match(/https?:\/\/[^\s\]]+\/book\/[A-Za-z0-9]+/)?.[0];
    expect(bookingUrl).toBeTruthy();

    // The corporation logo is embedded in the email footer
    expect(detail.HTML as string).toContain('<img');

    // The round page shows the invite as sent
    await page.reload();
    await expect(page.getByText('Invite sent')).toBeVisible();

    // The customer follows the emailed link and books
    await page.goto(bookingUrl!);
    await expect(page.getByRole('heading', { name: /Survey visit · Mail Street 7/ })).toBeVisible();
    const slotButtons = page.locator('section button');
    const firstSlot = (await slotButtons.first().textContent())!.trim();
    await slotButtons.first().click();
    await page.getByRole('button', { name: new RegExp(`^Book ${firstSlot}`) }).click();
    await expect(page.getByText('Your booked time')).toBeVisible();

    // The confirmation email lands after the next drain
    await request.post('/_test/drain-outbox');

    const after = await (await request.get(`${MAILPIT}/api/v1/messages`)).json();
    expect(after.total).toBe(2);
    expect(after.messages[0].Subject).toContain('Your visit is booked');
});

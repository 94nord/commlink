import { expect, test, type Page } from '@playwright/test';
import { receiveSms, seedCustomer } from './fixtures';

// The suite shares one database (reset only in auth.setup), so each test uses
// its own number and cleans up nothing.

/** The open conversation. Scoped, because a message body also shows in the
 *  list preview on the left. */
const thread = (page: Page) => page.getByRole('region', { name: 'Conversation' });

test('a text from an unknown number appears in the inbox and can be answered', async ({ page, request }) => {
    await receiveSms(request, '+4520123456', 'Min radiator drypper vand');

    await page.goto('/inbox');

    const conversation = page.getByRole('link', { name: /\+4520123456/ });
    await expect(conversation).toBeVisible();
    await expect(conversation).toContainText('Min radiator drypper vand');

    await conversation.click();
    await page.waitForURL(/\/inbox\/\d+/);

    await expect(page.getByRole('heading', { name: '+4520123456' })).toBeVisible();
    await expect(thread(page).getByText('Min radiator drypper vand')).toBeVisible();

    await page.getByLabel('Reply').fill('Vi kommer i morgen kl 9');
    await page.getByRole('button', { name: 'Send' }).click();

    await expect(thread(page).getByText('Vi kommer i morgen kl 9')).toBeVisible();
});

test('a reply from the customer lands in the same conversation', async ({ page, request }) => {
    await receiveSms(request, '+4531234567', 'Er I der?');
    await page.goto('/inbox');

    await page.getByRole('link', { name: /\+4531234567/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    await receiveSms(request, '+4531234567', 'Hallo?');

    // The pane polls, so this arrives without a reload.
    await expect(thread(page).getByText('Hallo?')).toBeVisible({ timeout: 15000 });
});

test('starting a conversation opens an empty one, and the first message creates it', async ({ page }) => {
    await page.goto('/inbox');

    // The dialog only picks who it is with.
    await page.getByRole('button', { name: 'Start conversation' }).first().click();
    // Written the way a person would; the server stores it as +4540123456.
    await page.getByLabel('To', { exact: true }).fill('40 12 34 56');
    await page.getByRole('button', { name: 'Start conversation' }).last().click();

    // An empty conversation, written from the inbox rather than the modal.
    await page.waitForURL(/\/inbox\/new/);
    await expect(page.getByRole('heading', { name: '+4540123456' })).toBeVisible();
    await expect(thread(page).getByText('Nothing sent yet')).toBeVisible();

    await page.getByLabel('Reply').fill('Vi har en ledig tid i morgen');
    await page.getByRole('button', { name: 'Send' }).click();

    await page.waitForURL(/\/inbox\/\d+/);
    await expect(thread(page).getByText('Vi har en ledig tid i morgen')).toBeVisible();
});

test('starting a conversation with a number we already know opens its history', async ({ page, request }) => {
    await receiveSms(request, '+4561234567', 'Jeg har skrevet foer');

    await page.goto('/inbox');
    await page.getByRole('button', { name: 'Start conversation' }).first().click();
    await page.getByLabel('To', { exact: true }).fill('61234567');
    await page.getByRole('button', { name: 'Start conversation' }).last().click();

    // Not a blank draft — the conversation that already exists.
    await page.waitForURL(/\/inbox\/\d+/);
    await expect(thread(page).getByText('Jeg har skrevet foer')).toBeVisible();
});

test('a recipient that is neither a number nor an email is refused in the dialog', async ({ page }) => {
    await page.goto('/inbox');
    await page.getByRole('button', { name: 'Start conversation' }).first().click();
    await page.getByLabel('To', { exact: true }).fill('not-an-address');
    await page.getByRole('button', { name: 'Start conversation' }).last().click();

    // Caught before any round trip, and the dialog stays open so it can be
    // fixed where it was typed.
    await expect(page.getByText('neither a phone number nor an email address')).toBeVisible();
    await expect(page.getByLabel('To', { exact: true })).toBeVisible();
});

test('an email address is refused by name at a firm with no email channel', async ({ page }) => {
    await page.goto('/inbox');
    await page.getByRole('button', { name: 'Start conversation' }).first().click();
    await page.getByLabel('To', { exact: true }).fill('anna@example.dk');
    await page.getByRole('button', { name: 'Start conversation' }).last().click();

    // The right shape, the wrong channel for this firm — said plainly
    // rather than after a whole message has been written.
    await expect(page.getByText('cannot send on email')).toBeVisible();
});

test('an unknown number can be given a name', async ({ page, request }) => {
    await seedCustomer(request, 'Hanne Jensen', 'hanne@example.test');
    await receiveSms(request, '+4550123456', 'Det er Hanne');

    await page.goto('/inbox');
    await page.getByRole('link', { name: /\+4550123456/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    await page.getByRole('button', { name: 'Link to customer' }).click();
    await page.getByRole('button', { name: /Hanne Jensen/ }).click();

    // The conversation is hers now, so it answers to her name.
    await expect(page.getByRole('heading', { name: 'Hanne Jensen' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Link to customer' })).toBeHidden();
});

test('a long conversation scrolls inside itself and keeps the reply box in view', async ({ page, request }) => {
    for (let i = 1; i <= 40; i++) {
        await receiveSms(request, '+4571234567', `Besked nummer ${i}`);
    }

    await page.goto('/inbox');
    await page.getByRole('link', { name: /\+4571234567/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);
    await expect(thread(page).getByText('Besked nummer 40', { exact: true })).toBeVisible();

    const timeline = page.locator('[data-slot="timeline"]');

    // The conversation really is taller than its pane, or this proves nothing.
    expect(await timeline.evaluate((el) => el.scrollHeight > el.clientHeight + 10)).toBe(true);

    // The bug: messages pushed the composer off the bottom of the page.
    await expect(page.getByLabel('Reply')).toBeInViewport();
    await expect(page.getByRole('button', { name: 'Send' })).toBeInViewport();

    // The window itself must not scroll — the message list does.
    expect(await page.evaluate(() => document.documentElement.scrollHeight > window.innerHeight + 2)).toBe(false);

    const distanceFromBottom = () => timeline.evaluate((el) => el.scrollHeight - el.scrollTop - el.clientHeight);

    // Opening a conversation lands at the newest message, not the oldest.
    await expect.poll(distanceFromBottom).toBeLessThan(20);

    // Scrolling up stays put, then sending returns to the bottom.
    await timeline.evaluate((el) => (el.scrollTop = 0));
    await page.getByLabel('Reply').fill('Vi er paa vej');
    await page.getByRole('button', { name: 'Send' }).click();

    await expect(thread(page).getByText('Vi er paa vej', { exact: true })).toBeInViewport();
    await expect.poll(distanceFromBottom).toBeLessThan(20);
});

test('unread messages are counted in the queue until the conversation is opened', async ({ page, request }) => {
    await receiveSms(request, '+4581234567', 'Foerste besked');
    await receiveSms(request, '+4581234567', 'Anden besked');

    await page.goto('/inbox');

    const row = page.getByRole('link', { name: /\+4581234567/ });
    await expect(row).toBeVisible();
    await expect(row.getByText('2', { exact: true })).toBeVisible();

    await row.click();
    await page.waitForURL(/\/inbox\/\d+/);

    // Reading it clears the count — the list polls, so no reload needed.
    await expect(row.getByText('2', { exact: true })).toBeHidden({ timeout: 15000 });

    // And it stays cleared once you move on. A list poll answered before the
    // server recorded the read used to land afterwards and put the count
    // back, which only became visible on clicking away.
    await page.getByRole('link', { name: /\+4520123456/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);
    await page.waitForTimeout(6000);
    await expect(row.getByText('2', { exact: true })).toBeHidden();
});

test.describe('as a guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('the inbox redirects to the login page', async ({ page }) => {
        await page.goto('/inbox');

        await page.waitForURL('/login');
        await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    });
});

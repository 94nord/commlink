import { expect, test } from '@playwright/test';
import { receiveSms, setModule } from './fixtures';

/**
 * What a firm that is not switched on for SMS sees.
 *
 * The whole suite shares one database, so every test here puts the module
 * back before it finishes — a leaked "off" would freeze every test after it.
 */
test.describe('a firm switched off for SMS', () => {
    test.afterEach(async ({ request }) => {
        await setModule(request, 'sms', true);
    });

    test('keeps the history readable but offers no way to reply', async ({ page, request }) => {
        // History first, while the module is still on: that history is the
        // whole reason the conversation stays visible afterwards.
        await receiveSms(request, '+4540111222', 'Hvornår kommer I?');
        await setModule(request, 'sms', false);

        await page.goto('/inbox');

        const conversation = page.getByRole('link', { name: /\+4540111222/ });
        await expect(conversation).toBeVisible();
        await conversation.click();
        await page.waitForURL(/\/inbox\/\d+/);

        // Frozen, not gone.
        await expect(page.getByRole('region', { name: 'Conversation' }).getByText('Hvornår kommer I?')).toBeVisible();
        await expect(page.getByText('no longer switched on for this channel')).toBeVisible();
        await expect(page.getByLabel('Reply')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Send' })).toBeHidden();
    });

    test('says so instead of offering a new conversation it cannot send', async ({ page, request }) => {
        await setModule(request, 'sms', false);

        await page.goto('/inbox');
        await page.getByRole('button', { name: 'Start conversation' }).click();

        await expect(page.getByText('no channel switched on yet')).toBeVisible();
        await expect(page.getByLabel('To')).toBeHidden();
    });

    test('can reply again once it is switched back on', async ({ page, request }) => {
        await receiveSms(request, '+4540333444', 'Er I der?');
        await setModule(request, 'sms', false);
        await setModule(request, 'sms', true);

        await page.goto('/inbox');
        await page.getByRole('link', { name: /\+4540333444/ }).click();
        await page.waitForURL(/\/inbox\/\d+/);

        await page.getByLabel('Reply').fill('Ja, vi kommer i morgen');
        await page.getByRole('button', { name: 'Send' }).click();

        await expect(page.getByRole('region', { name: 'Conversation' }).getByText('Ja, vi kommer i morgen')).toBeVisible();
    });
});

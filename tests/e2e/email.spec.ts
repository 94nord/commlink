import { expect, test, type Page } from '@playwright/test';
import { receiveEmail, receiveSms, setModule } from './fixtures';

// The suite shares one database, so each test uses its own sender address.
const thread = (page: Page) => page.getByRole('region', { name: 'Conversation' });

// The seeded firm is an SMS firm — other specs assert on exactly that — so
// this file switches email on for its own run and back off afterwards.
test.beforeAll(async ({ request }) => {
    await setModule(request, 'email', true);
});

test.afterAll(async ({ request }) => {
    await setModule(request, 'email', false);
});

test('an email arrives, opens a named exchange, and can be answered', async ({ page, request }) => {
    await receiveEmail(request, {
        from: 'Anna Larsen <anna@kunde.test>',
        subject: 'Utæt radiator',
        html: '<div>Hej,</div><p>Min radiator drypper. Kan I komme?</p>',
    });

    await page.goto('/inbox');
    await page.getByRole('link', { name: /anna@kunde\.test/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    // The rich body renders as formatting, not as literal tags.
    await expect(thread(page).getByText('Min radiator drypper')).toBeVisible();
    await expect(thread(page).getByText('<p>')).toBeHidden();

    // No exchange is open, so the reply would start a new one — which needs
    // a name before it can go anywhere.
    await expect(page.getByLabel('Regarding')).toHaveValue('new');
    await page.getByRole('textbox', { name: 'Reply' }).fill('Vi kommer tirsdag');
    await expect(page.getByRole('button', { name: 'Send' })).toBeDisabled();

    // Answering the mail that arrived means opening it first.
    await page
        .getByRole('group', { name: 'Conversations on this address' })
        .getByRole('button', { name: /Utæt radiator/ })
        .click();
    await expect(page.getByLabel('Regarding')).toBeDisabled();

    await page.getByRole('button', { name: 'Send' }).click();
    await expect(thread(page).getByText('Vi kommer tirsdag')).toBeVisible();
});

test('a reply on the chain lands in the same exchange, an unrelated email starts another', async ({ page, request }) => {
    await receiveEmail(request, { from: 'bo@kunde.test', subject: 'Nyt bad', text: 'Hvad koster det?', message_id: 'bo-1@kunde.test' });
    await receiveEmail(request, {
        from: 'bo@kunde.test',
        subject: 'SV: Nyt bad',
        text: 'Og hvornår?',
        message_id: 'bo-2@kunde.test',
        in_reply_to: 'bo-1@kunde.test',
    });
    await receiveEmail(request, { from: 'bo@kunde.test', subject: 'Faktura', text: 'Jeg mangler den', message_id: 'bo-3@kunde.test' });

    await page.goto('/inbox');
    await page.getByRole('link', { name: /bo@kunde\.test/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    // One address, so the thread row shows both exchanges by subject.
    const threadRow = page.getByRole('group', { name: 'Conversations on this address' });
    await expect(threadRow.getByRole('button', { name: /Nyt bad/ })).toBeVisible();
    await expect(threadRow.getByRole('button', { name: /Faktura/ })).toBeVisible();

    // The chained reply joined the first exchange rather than starting one.
    await threadRow.getByRole('button', { name: /Nyt bad/ }).click();
    await expect(thread(page).getByText('Hvad koster det?')).toBeVisible();
    await expect(thread(page).getByText('Og hvornår?')).toBeVisible();
    await expect(thread(page).getByText('Jeg mangler den')).toBeHidden();

    // Picking an exchange decides where a reply goes, so the bottom bar
    // stops being a choice and becomes a read-out.
    await expect(page.getByLabel('Regarding')).toBeDisabled();
});

test('a new exchange can be started, and needs a subject first', async ({ page, request }) => {
    await receiveEmail(request, { from: 'carl@kunde.test', subject: 'Tilbud', text: 'Ja tak' });

    await page.goto('/inbox');
    await page.getByRole('link', { name: /carl@kunde\.test/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    // No thread is open, so the selector already sits on "new" and the
    // subject field is part of the message being written.
    await expect(page.getByLabel('Regarding')).toHaveValue('new');

    // Send stays shut until the new exchange has a name.
    await page.getByRole('textbox', { name: 'Reply' }).fill('Vi har en ledig tid');
    await expect(page.getByRole('button', { name: 'Send' })).toBeDisabled();

    await page.getByLabel('Subject', { exact: true }).fill('Ny aftale');
    await page.getByRole('button', { name: 'Send' }).click();

    await expect(thread(page).getByText('Vi har en ledig tid')).toBeVisible();

    // It really is a separate exchange, not an addition to the old one.
    const threadRow = page.getByRole('group', { name: 'Conversations on this address' });
    await expect(threadRow.getByRole('button', { name: /Ny aftale/ })).toBeVisible();
    await expect(threadRow.getByRole('button', { name: /Tilbud/ })).toBeVisible();
});

test('an SMS conversation gets no thread row, no toolbar and no subject field', async ({ page, request }) => {
    // Its own number: this file must not depend on another spec having run.
    await receiveSms(request, '+4544332211', 'Kan I komme?');

    await page.goto('/inbox');
    await page.getByRole('link', { name: /\+4544332211/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    // A number is one unbroken exchange and carries no markup, so none of
    // the email machinery has anything to offer.
    await expect(page.getByRole('group', { name: 'Conversations on this address' })).toBeHidden();
    await expect(page.getByLabel('Regarding')).toBeHidden();
    await expect(page.getByLabel('Subject', { exact: true })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Bold' })).toBeHidden();
});

test('switching between exchanges keeps the pane on screen', async ({ page, request }) => {
    // Distinctive words: a short body like "En" is a substring of half the
    // subjects and buttons on the page.
    await receiveEmail(request, { from: 'dorte@kunde.test', subject: 'Alfa', text: 'Kroppen i alfa', message_id: 'd-1@kunde.test' });
    await receiveEmail(request, { from: 'dorte@kunde.test', subject: 'Beta', text: 'Kroppen i beta', message_id: 'd-2@kunde.test' });

    await page.goto('/inbox');
    await page.getByRole('link', { name: /dorte@kunde\.test/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    const threadRow = page.getByRole('group', { name: 'Conversations on this address' });
    const heading = page.getByRole('heading', { name: /dorte@kunde\.test/ });

    // The header must never blank while the timeline is being refetched —
    // it used to, because narrowing the view changed the query key and
    // handed back an empty cache entry.
    await threadRow.getByRole('button', { name: /Alfa/ }).click();
    await expect(heading).toBeVisible();
    await expect(thread(page).getByText('Kroppen i alfa')).toBeVisible();

    await threadRow.getByRole('button', { name: /Beta/ }).click();
    await expect(heading).toBeVisible();
    await expect(thread(page).getByText('Kroppen i beta')).toBeVisible();
});

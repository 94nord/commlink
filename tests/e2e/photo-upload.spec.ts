import { expect, test } from '@playwright/test';
import { receiveSms, seedUploadLink } from './fixtures';

// A 1x1 PNG — enough for the server's image validation to see real bytes.
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

// A minimal but structurally real PDF — enough for the server's content
// sniffing (Fileinfo via the `mimes` rule) to recognize it as one.
const PDF = Buffer.from(
    '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
        '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n' +
        'trailer<</Size 4/Root 1 0 R>>\nstartxref\n0\n%%EOF',
);

test.describe('the customer, who has no account', () => {
    // The rest of the suite runs as the logged-in firm; this page is opened
    // by someone who has only ever received a text.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('sends a photo through a texted link', async ({ page, request }) => {
        await receiveSms(request, '+4520987654', 'Her er noget galt');
        const token = await seedUploadLink(request);

        // The shared auth state normally carries this; a blank context does not.
        await page.addInitScript(() => window.localStorage.setItem('language', 'en'));
        await page.goto(`/u/${token}`);

        await expect(page.getByRole('heading', { name: /Send files to/ })).toBeVisible();

        await page.locator('input[type=file]').setInputFiles({
            name: 'leak.png',
            mimeType: 'image/png',
            buffer: PNG,
        });

        await page.getByRole('button', { name: 'Send', exact: true }).click();

        await expect(page.getByText('Thanks — that came through')).toBeVisible();

        // No lifetime cap on a link — sending a second batch right after
        // must still work rather than the page reporting itself used up.
        await page.locator('input[type=file]').setInputFiles({
            name: 'meter.png',
            mimeType: 'image/png',
            buffer: PNG,
        });
        await page.getByRole('button', { name: 'Send', exact: true }).click();
        await expect(page.getByText('Thanks — that came through')).toBeVisible();
    });

    test('sends a photo and a PDF together', async ({ page, request }) => {
        await receiveSms(request, '+4520987655', 'Her er billede og faktura');
        const token = await seedUploadLink(request);

        await page.addInitScript(() => window.localStorage.setItem('language', 'en'));
        await page.goto(`/u/${token}`);

        await page.locator('input[type=file]').setInputFiles([
            { name: 'leak.png', mimeType: 'image/png', buffer: PNG },
            { name: 'invoice.pdf', mimeType: 'application/pdf', buffer: PDF },
        ]);

        // The PDF preview is a file-icon card, not a broken <img> — proves
        // the picker branches on file type rather than assuming an image.
        await expect(page.getByText('invoice.pdf')).toBeVisible();

        await page.getByRole('button', { name: 'Send', exact: true }).click();
        await expect(page.getByText('Thanks — that came through')).toBeVisible();
    });

    test('is told plainly when the link is dead', async ({ page }) => {
        await page.addInitScript(() => window.localStorage.setItem('language', 'en'));
        await page.goto(`/u/${'x'.repeat(40)}`);

        await expect(page.getByRole('heading', { name: 'This link no longer works' })).toBeVisible();
    });
});

test('the photo and PDF the customer sent show up in the thread', async ({ page, request }) => {
    await receiveSms(request, '+4521112222', 'Sender billede og faktura');
    const token = await seedUploadLink(request);

    // The upload endpoint is public and ignores whatever session cookies
    // this page happens to carry, so the same authenticated `page` can
    // complete the customer-side upload and then check the inbox — no need
    // for a second, logged-out context. (Playwright's API request context
    // does not support more than one file per multipart field in this
    // version, which is the other reason this goes through the real page
    // rather than a raw POST.)
    await page.goto(`/u/${token}`);
    await page.locator('input[type=file]').setInputFiles([
        { name: 'leak.png', mimeType: 'image/png', buffer: PNG },
        { name: 'invoice.pdf', mimeType: 'application/pdf', buffer: PDF },
    ]);
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    await expect(page.getByText('Thanks — that came through')).toBeVisible();

    await page.goto('/inbox');
    await page.getByRole('link', { name: /\+4521112222/ }).click();
    await page.waitForURL(/\/inbox\/\d+/);

    const thread = page.getByRole('region', { name: 'Conversation' });

    // By its alt text, not the role alone — the composer's toolbar icons are
    // images too.
    const photo = thread.getByAltText('Picture sent by the customer');
    await expect(photo).toBeVisible();

    // Present in the DOM is not the same as served: make sure the bytes
    // actually came back through the authenticated attachment route.
    await expect(photo).toHaveJSProperty('naturalWidth', 1);

    // The PDF renders as a file card with its real name, not as a broken
    // image — and its download link is reachable and forces a save.
    const pdfLink = thread.getByRole('link', { name: /invoice\.pdf/ });
    await expect(pdfLink).toBeVisible();
    const download = await Promise.all([page.waitForEvent('download'), pdfLink.click()]).then(([d]) => d);
    expect(download.suggestedFilename()).toBe('invoice.pdf');
});

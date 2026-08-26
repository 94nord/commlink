import { APIRequestContext } from '@playwright/test';

/** The Laravel API lives on a separate origin/port from the web/ SPA under test. */
export const API_BASE_URL = `http://localhost:${process.env.TEST_HTTP_PORT ?? 9080}`;

/** Throwaway credentials for the isolated test database — not secrets. */
export const testUser = {
    corporation: 'E2E Inspections',
    name: 'E2E Admin',
    email: 'admin@e2e.test',
    password: 'e2e-password',
};

/** Reset the backend to a freshly-migrated, empty database. */
export async function resetDatabase(request: APIRequestContext) {
    const response = await request.post(`${API_BASE_URL}/_test/reset`);
    if (!response.ok()) {
        throw new Error(`/_test/reset failed: ${response.status()}`);
    }
}

/** Seed a corporation with its admin user, and an SMS number to text from. */
export async function seedUser(request: APIRequestContext, user = testUser) {
    const response = await request.post(`${API_BASE_URL}/_test/seed`, { data: user });
    if (!response.ok()) {
        throw new Error(`/_test/seed failed: ${response.status()}`);
    }
}

/** Create a customer to link conversations to. */
export async function seedCustomer(request: APIRequestContext, name: string, email: string) {
    const response = await request.post(`${API_BASE_URL}/_test/customer`, { data: { name, email } });
    if (!response.ok()) {
        throw new Error(`/_test/customer failed: ${response.status()}`);
    }
}

/**
 * Pretend a customer texted the firm's number. Runs the real adapter and
 * ingest — only the Twilio signature check is skipped.
 */
export async function receiveSms(request: APIRequestContext, from: string, body: string) {
    const response = await request.post(`${API_BASE_URL}/_test/inbound-sms`, { data: { from, body } });
    if (!response.ok()) {
        throw new Error(`/_test/inbound-sms failed: ${response.status()}`);
    }
}

/**
 * Pretend a customer emailed the firm's address. Runs the real adapter and
 * ingest, including threading — only the provider's signature check is
 * missing, because there is no provider yet.
 */
export async function receiveEmail(
    request: APIRequestContext,
    email: { from: string; subject: string; text?: string; html?: string; message_id?: string; in_reply_to?: string },
) {
    const response = await request.post(`${API_BASE_URL}/_test/inbound-email`, { data: email });
    if (!response.ok()) {
        throw new Error(`/_test/inbound-email failed: ${response.status()}`);
    }
}

/**
 * Switch the firm on or off for a module, as ops would.
 *
 * The suite shares one database and runs single-file with one worker, so a
 * test that switches something off must switch it back on again — otherwise
 * every test after it inherits a frozen inbox.
 */
export async function setModule(request: APIRequestContext, module: string, enabled: boolean) {
    const response = await request.post(`${API_BASE_URL}/_test/module`, { data: { module, enabled } });
    if (!response.ok()) {
        throw new Error(`/_test/module failed: ${response.status()}`);
    }
}

/**
 * Mint a photo upload link for the newest conversation, as the "ask for
 * photos" button does. Returns the raw token — the only time it exists.
 */
export async function seedUploadLink(request: APIRequestContext): Promise<string> {
    const response = await request.post(`${API_BASE_URL}/_test/upload-link`);
    if (!response.ok()) {
        throw new Error(`/_test/upload-link failed: ${response.status()}`);
    }

    return (await response.json()).token;
}

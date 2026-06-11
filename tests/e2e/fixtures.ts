import { APIRequestContext } from '@playwright/test';

/** Throwaway credentials for the isolated test database — not secrets. */
export const testUser = {
    corporation: 'E2E Inspections',
    name: 'E2E Admin',
    email: 'admin@e2e.test',
    password: 'e2e-password',
};

/** Reset the backend to a freshly-migrated, empty database. */
export async function resetDatabase(request: APIRequestContext) {
    const response = await request.post('/_test/reset');
    if (!response.ok()) {
        throw new Error(`/_test/reset failed: ${response.status()}`);
    }
}

/** Seed a corporation with its admin user. */
export async function seedUser(request: APIRequestContext, user = testUser) {
    const response = await request.post('/_test/seed', { data: user });
    if (!response.ok()) {
        throw new Error(`/_test/seed failed: ${response.status()}`);
    }
}

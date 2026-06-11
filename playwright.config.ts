import { defineConfig, devices } from '@playwright/test';

/**
 * E2E tests run against the isolated, containerized test stack (Compose
 * profile "test" on port 9080), which boots into a freshly-migrated empty
 * database. Build assets first: `make test` runs the full cycle.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:9080',
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'docker compose --profile test up --build',
        url: 'http://localhost:9080/up',
        reuseExistingServer: true,
        timeout: 300_000,
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'tests/e2e/.auth/user.json',
            },
            dependencies: ['setup'],
        },
    ],
});

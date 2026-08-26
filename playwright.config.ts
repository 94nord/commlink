import { defineConfig, devices } from '@playwright/test';

/**
 * E2E tests run against the isolated, containerized test stack (Compose
 * profile "test"): the built web/ SPA on port 9081, calling the Laravel API
 * (api-test) on port 9080. `make test` runs the full cycle.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:9081',
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'docker compose --profile test up --build',
        // The API (migrations + PHP-FPM) is the real readiness bottleneck;
        // the static web-test nginx comes up almost instantly once built.
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

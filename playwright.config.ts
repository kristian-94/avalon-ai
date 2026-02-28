import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 120_000,  // 2min per test (human-in-the-loop adds interaction time)
    expect: {
        timeout: 10_000,
    },
    use: {
        baseURL: 'http://localhost:8000',
        headless: false,
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
})

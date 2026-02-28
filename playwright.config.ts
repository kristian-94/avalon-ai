import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/global-setup.ts',
    globalTeardown: './tests/Browser/global-setup.ts',
    timeout: 660_000,  // 11min per test (OpenAI API latency)
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

import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/global-setup.ts',
    globalTeardown: './tests/Browser/global-setup.ts',
    timeout: 300_000,  // 5min per test (real OpenAI is slower than random AI)
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

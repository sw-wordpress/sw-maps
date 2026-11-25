import { defineConfig, devices } from '@playwright/test';

/** The real admin app with a simulated REST API (page.route); no WordPress, credentials or database. */
export default defineConfig({
  testDir: './mocked',
  outputDir: './test-results/mocked',
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5174',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'npm --prefix ../apps/admin run dev -- --strictPort',
    url: 'http://127.0.0.1:5174',
    reuseExistingServer: false,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});

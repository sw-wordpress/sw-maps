import { defineConfig, devices } from '@playwright/test';
import { loadGeneratedEnv } from './support/env';

loadGeneratedEnv();

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  outputDir: './test-results',
  forbidOnly: !!process.env['CI'],
  // Chromium aborts requests with ERR_NETWORK_CHANGED when the host changes a network interface (Docker veth churn).
  // Two retries; a failure after both is real. Retried tests are reported as flaky; there is no sleep based workaround.
  retries: 2,
  // One WordPress container serves all workers; more than three workers made page loads fail on busy hosts.
  workers: 3,
  fullyParallel: true,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  timeout: 45_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL: process.env['SWMAPS_E2E_BASE_URL'],
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1280, height: 900 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});

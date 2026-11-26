import { mkdir, rm } from 'node:fs/promises';
import { chromium } from '@playwright/test';
import {
  AUTH_DIR,
  authFile,
  loadGeneratedEnv,
  PLUGIN_PAGE,
  requireEnv,
  ROLES,
} from './support/env';
import { login } from './support/login';

/** Logs in once per role (real login form) and stores the session in the git-ignored .auth directory. */
export default async function globalSetup(): Promise<void> {
  loadGeneratedEnv();
  const baseURL = requireEnv('SWMAPS_E2E_BASE_URL');
  await rm(AUTH_DIR, { recursive: true, force: true });
  await mkdir(AUTH_DIR, { recursive: true });
  const browser = await chromium.launch();
  try {
    for (const role of ROLES) {
      const context = await browser.newContext();
      const page = await context.newPage();
      await login(page, baseURL, role);
      // Warm-up: the first page loads of a fresh WordPress (opcache) are slow and would make early tests flaky.
      if (role === 'administrator') {
        await page.goto(`${baseURL}${PLUGIN_PAGE}`, { timeout: 60_000, waitUntil: 'networkidle' });
        // Also warm the REST stack (cold PHP opcache makes the first API calls slow) and the lazy route chunks.
        await page.evaluate(async () => {
          const config = (
            window as unknown as { swmapsConfig: { restRoot: string; nonce: string } }
          ).swmapsConfig;
          for (const path of ['maps', 'providers', 'settings', 'status']) {
            await fetch(`${config.restRoot}${path}`, { headers: { 'X-WP-Nonce': config.nonce } });
          }
        });
        for (const hash of ['#/maps', '#/providers', '#/settings']) {
          await page.goto(`${baseURL}${PLUGIN_PAGE}${hash}`, { waitUntil: 'networkidle' });
        }
      }
      await context.storageState({ path: authFile(role) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}

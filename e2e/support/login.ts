import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';
import type { Role } from './env';
import { credentials } from './env';

/** Logs in through the real wp-login.php form (no cookie forging). */
export async function login(page: Page, baseURL: string, role: Role): Promise<void> {
  const { login: user, password } = credentials(role);
  await page.goto(`${baseURL}/wp-login.php`);
  await page.getByLabel('Username or Email Address').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

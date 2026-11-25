import { expect, test } from '@playwright/test';
import { authFile } from '../support/env';
import { mockTiles } from '../support/tiles';
import { seedMap } from '../support/fixtures';
import { WpApi } from '../support/wp';

test.describe.configure({ mode: 'serial' });

// Compatibility with a real consent banner plugin (Complianz). Commercial plugins (e.g. Borlabs) cannot be installed
// from wordpress.org and are not covered; they integrate through the same WP Consent API.
test('Complianz: no tiles before the visitor accepts, tiles after accepting', async ({
  browser,
  page,
  baseURL,
}) => {
  const ctx = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  const admin = await ctx.newPage();
  const api = await WpApi.create(admin);
  const nonce = await admin.evaluate(
    () => (window as unknown as { swmapsConfig: { nonce: string } }).swmapsConfig.nonce,
  );
  const activate = await admin.request.put(
    `${baseURL}/index.php?rest_route=/wp/v2/plugins/complianz-gdpr/complianz-gpdr`,
    { headers: { 'X-WP-Nonce': nonce }, data: { status: 'active' } },
  );
  expect(activate.ok(), await activate.text()).toBe(true);
  const seeded = await seedMap(browser, 'E2E Complianz');
  void api;
  try {
    const log = await mockTiles(page, baseURL!);
    await page.goto(
      `${seeded.shortcodeUrl}${seeded.shortcodeUrl.includes('?') ? '&' : '?'}swmap_consent_gate=1`,
    );
    const state = page.locator('.swmap');
    await expect(state).toBeVisible();
    // Complianz (opt-in region) must hold the map back until the visitor accepts.
    await expect(state).toHaveAttribute('data-state', 'waiting-consent');
    await page.waitForLoadState('networkidle');
    expect(log.tiles).toEqual([]);
    await page.locator('.cmplz-accept, .cmplz-btn.cmplz-accept').first().click();
    await expect(state).toHaveAttribute('data-state', 'ready');
    expect(log.tiles.length).toBeGreaterThan(0);
  } finally {
    await admin.request.put(
      `${baseURL}/index.php?rest_route=/wp/v2/plugins/complianz-gdpr/complianz-gpdr`,
      { headers: { 'X-WP-Nonce': nonce }, data: { status: 'inactive' } },
    );
    await ctx.close();
  }
});

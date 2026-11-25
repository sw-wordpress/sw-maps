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
    `${baseURL}/index.php?rest_route=/wp/v2/plugins/complianz-gdpr/complianz-gdpr`,
    { headers: { 'X-WP-Nonce': nonce }, data: { status: 'active' } },
  );
  test.skip(!activate.ok(), 'complianz-gdpr is not installed in this environment');
  const seeded = await seedMap(browser, 'E2E Complianz');
  void api;
  try {
    const log = await mockTiles(page, baseURL!);
    await page.goto(seeded.shortcodeUrl);
    const state = page.locator('.swmap');
    await expect(state).toBeVisible();
    const gated = (await state.getAttribute('data-state')) === 'waiting-consent';
    test.skip(
      !gated,
      'Complianz grants consent by default for this region/configuration; nothing to test',
    );
    await page.waitForLoadState('networkidle');
    expect(log.tiles).toEqual([]);
    await page.locator('.cmplz-accept, .cmplz-btn.cmplz-accept').first().click();
    await expect(state).toHaveAttribute('data-state', 'ready');
    expect(log.tiles.length).toBeGreaterThan(0);
  } finally {
    await admin.request.put(
      `${baseURL}/index.php?rest_route=/wp/v2/plugins/complianz-gdpr/complianz-gdpr`,
      { headers: { 'X-WP-Nonce': nonce }, data: { status: 'inactive' } },
    );
    await ctx.close();
  }
});

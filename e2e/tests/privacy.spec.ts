import { expect, test } from '@playwright/test';
import type { Browser } from '@playwright/test';
import { authFile } from '../support/env';
import { mockTiles, tileHostsOnly } from '../support/tiles';
import type { SeededMap } from '../support/fixtures';
import { seedMap } from '../support/fixtures';
import { WpApi } from '../support/wp';

let seeded: SeededMap;
let plainPage: string;
let clickToLoadPage: string;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  seeded = await seedMap(browser, 'E2E Privacy');
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: process.env['SWMAPS_E2E_BASE_URL'],
  });
  const api = await WpApi.create(await context.newPage());
  plainPage = await api.createPage('E2E Without map', '<p>No map here.</p>');
  clickToLoadPage = await api.createPage(
    'E2E Click to load',
    `[sw_map id="${seeded.mapId}" click_to_load="1" lazy="0"]`,
  );
  await context.close();
});

test('a page without a map loads no bundle and makes no external request', async ({
  page,
  baseURL,
}) => {
  const log = await mockTiles(page, baseURL!);
  await page.goto(plainPage);
  await expect(page.getByText('No map here.')).toBeVisible();
  await page.waitForLoadState('networkidle');
  expect(log.bundle).toEqual([]);
  expect(log.external).toEqual([]);
  expect(
    await page.locator('link[href*="swmaps-viewer"], script[src*="swmaps-viewer"]').count(),
  ).toBe(0);
});

test('no tile request before consent; the list stays usable; tiles load after consent', async ({
  page,
  baseURL,
}) => {
  const log = await mockTiles(page, baseURL!);
  await page.goto(
    `${seeded.shortcodeUrl}${seeded.shortcodeUrl.includes('?') ? '&' : '?'}swmap_consent_api=1`,
  );
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'waiting-consent');
  await expect(page.locator('.swmap__placeholder')).toBeVisible();
  await expect(page.locator('.swmap__list')).toContainText('Schloss');
  await page.waitForLoadState('networkidle');
  expect(log.external).toEqual([]);
  expect(await page.locator('.swmap .ol-viewport').count()).toBe(0);

  await page.evaluate(() => {
    localStorage.setItem('swmap_consent', 'marketing');
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
  });
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  expect(log.tiles.length).toBeGreaterThan(0);
  expect(tileHostsOnly(log)).toBe(true);
});

test('click to load blocks external requests until the visitor clicks', async ({
  page,
  baseURL,
}) => {
  const log = await mockTiles(page, baseURL!);
  await page.goto(clickToLoadPage);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'waiting-click');
  await expect(page.locator('.swmap__list')).toContainText('Schloss');
  await page.waitForLoadState('networkidle');
  expect(log.external).toEqual([]);
  await page.locator('.swmap__load').click();
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  expect(log.tiles.length).toBeGreaterThan(0);
  expect(tileHostsOnly(log)).toBe(true);
});

test('with OpenStreetMap no Google resource is requested', async ({ page, baseURL }) => {
  const log = await mockTiles(page, baseURL!);
  await page.goto(seeded.shortcodeUrl);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  expect(log.external.filter((u) => /google|gstatic|googleapis/.test(u))).toEqual([]);
});

test('works with the real WP Consent API plugin', async ({ page, baseURL }) => {
  const log = await mockTiles(page, baseURL!);
  const response = await page.goto(
    `${seeded.shortcodeUrl}${seeded.shortcodeUrl.includes('?') ? '&' : '?'}swmap_real_consent=1`,
  );
  expect(response?.ok()).toBe(true);
  const apiLoaded = await page.evaluate(
    () => typeof (window as unknown as { wp_has_consent?: unknown }).wp_has_consent === 'function',
  );
  test.skip(
    !apiLoaded,
    'The wp-consent-api plugin is not installed in this environment (no network during provisioning).',
  );

  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'waiting-consent');
  await page.waitForLoadState('networkidle');
  expect(log.external).toEqual([]);
  await expect(page.locator('.swmap__list')).toContainText('Schloss');

  // The plugin's own API grants the consent and fires its change event.
  await page.evaluate(() =>
    (window as unknown as { wp_set_consent: (c: string, v: string) => void }).wp_set_consent(
      'marketing',
      'allow',
    ),
  );
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  expect(log.tiles.length).toBeGreaterThan(0);
  expect(tileHostsOnly(log)).toBe(true);

  // Revoking is respected on the next page view.
  await page.evaluate(() =>
    (window as unknown as { wp_set_consent: (c: string, v: string) => void }).wp_set_consent(
      'marketing',
      'deny',
    ),
  );
  await page.reload();
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'waiting-consent');
});

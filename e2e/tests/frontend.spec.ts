import { expect, test } from '@playwright/test';
import type { Browser } from '@playwright/test';
import { mockTiles } from '../support/tiles';
import type { SeededMap } from '../support/fixtures';
import { seedMap } from '../support/fixtures';

let seeded: SeededMap;
let second: SeededMap;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  seeded = await seedMap(browser, 'E2E Frontend');
  second = await seedMap(browser, 'E2E Second');
});

test('shortcode and block show the same published map without an extra data request', async ({
  page,
  baseURL,
}) => {
  const log = await mockTiles(page, baseURL!);
  const dataRequests: string[] = [];
  page.on('request', (r) => {
    if (r.url().includes('rest_route') || r.url().includes('/wp-json/sw-maps'))
      dataRequests.push(r.url());
  });
  const lists: string[] = [];
  for (const url of [seeded.shortcodeUrl, seeded.blockUrl]) {
    await page.goto(url);
    await expect(page.locator('.swmap .ol-viewport canvas')).toBeVisible();
    await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
    lists.push((await page.locator('.swmap__list').innerText()).replace(/Show on map/g, '').trim());
    await expect(page.locator('.swmap__attribution a', { hasText: 'OpenStreetMap' })).toBeVisible();
  }
  expect(lists[0]).toBe(lists[1]);
  expect(lists[0]).toContain('Schloss');
  expect(dataRequests).toEqual([]);
  expect(log.tiles.length).toBeGreaterThan(0);
});

test('several maps on one page work independently and share one bundle', async ({
  page,
  baseURL,
  browser,
}) => {
  const log = await mockTiles(page, baseURL!);
  const combined = await (async () => {
    const { WpApi } = await import('../support/wp');
    const context = await browser.newContext({
      storageState: (await import('../support/env')).authFile('administrator'),
      baseURL,
    });
    const admin = await context.newPage();
    const api = await WpApi.create(admin);
    const link = await api.createPage(
      'E2E Two maps',
      `[sw_map id="${seeded.mapId}"]\n\n[sw_map id="${second.mapId}"]`,
    );
    await context.close();
    return link;
  })();
  await page.goto(combined);
  // The second map starts lazily once it comes close to the viewport.
  await page.locator('.swmap').nth(1).scrollIntoViewIfNeeded();
  await expect(page.locator('.swmap[data-state="ready"]')).toHaveCount(2);
  await expect(page.locator('.swmap .ol-viewport canvas')).toHaveCount(2);
  expect(log.bundle).toHaveLength(1);
  const first = page.locator('.swmap').first();
  await first.locator('.swmap__show').first().click();
  await expect(first.locator('.swmap-popup')).toBeVisible();
  await expect(page.locator('.swmap').nth(1).locator('.swmap-popup')).toHaveCount(0);
});

test('the location list is keyboard operable and synchronised with the map', async ({
  page,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  await page.goto(seeded.shortcodeUrl);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  const button = page.locator('.swmap__show').first();
  await button.focus();
  await expect(button).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('.swmap-popup__title')).toHaveText('Schloss');
  await expect(page.locator('.swmap__list li').first()).toHaveClass(/is-selected/);
  await page.locator('.swmap-popup__close').click();
  await expect(page.locator('.swmap-popup')).toHaveCount(0);
  await expect(page.locator('.swmap__list li.is-selected')).toHaveCount(0);
});

test('mobile layout has no horizontal overflow', async ({ browser, baseURL }) => {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    baseURL,
  });
  const page = await context.newPage();
  await mockTiles(page, baseURL!);
  await page.goto(seeded.shortcodeUrl);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - window.innerWidth,
  );
  expect(overflow).toBeLessThanOrEqual(0);
  const box = (await page.locator('.swmap__stage').boundingBox())!;
  expect(box.width).toBeLessThanOrEqual(390);
  await context.close();
});

test('tile failures show a message and keep the list usable', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost|127\.0\.0\.1)/, (route) => route.abort());
  await page.goto(seeded.shortcodeUrl);
  await expect(page.locator('.swmap__notice')).toBeVisible();
  await expect(page.locator('.swmap__list')).toContainText('Schloss');
});

test('the attribution stays visible in fullscreen', async ({ page, baseURL }) => {
  await mockTiles(page, baseURL!);
  await page.goto(seeded.shortcodeUrl);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  await page.locator('.swmap__fullscreen').click();
  await expect(page.locator('.swmap__stage')).toHaveClass(/is-fullscreen/);
  await expect(page.locator('.swmap__stage .swmap__attribution')).toBeVisible();
  await page.keyboard.press('Escape');
});

test('the map is not initialised before it scrolls into view (lazy)', async ({ page, baseURL }) => {
  const log = await mockTiles(page, baseURL!);
  const filler = '<p style="height:3000px">spacer</p>';
  const { WpApi } = await import('../support/wp');
  const context = await page
    .context()
    .browser()!
    .newContext({
      storageState: (await import('../support/env')).authFile('administrator'),
      baseURL,
    });
  const admin = await context.newPage();
  const link = await (
    await WpApi.create(admin)
  ).createPage(
    'E2E Lazy',
    `<!-- wp:html -->${filler}<!-- /wp:html -->[sw_map id="${seeded.mapId}"]`,
  );
  await context.close();
  await page.goto(link);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'idle');
  expect(log.tiles).toHaveLength(0);
  await page.locator('.swmap').scrollIntoViewIfNeeded();
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
});

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Browser, Page } from '@playwright/test';
import { authFile } from '../support/env';
import { mockTiles } from '../support/tiles';
import { seedMap } from '../support/fixtures';
import type { SeededMap } from '../support/fixtures';
import { WpApi } from '../support/wp';
import { openAdmin } from '../support/admin';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const SCHEMES = ['light', 'dark'] as const;

let seeded: SeededMap;
let filterPage: string;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  seeded = await seedMap(browser, 'E2E A11y');
  const context = await browser.newContext({ storageState: authFile('administrator') });
  const page = await context.newPage();
  const api = await WpApi.create(page);
  const map = await api.createMap('E2E A11y filters');
  const layer = await api.addLayer(map.id, 'Orte');
  const cat = await api.post<{ id: number }>('/sw-maps/v1/categories', {
    title: 'A11y huts',
    style: { color: '#007700', icon: 'builtin:home' },
  });
  for (let i = 0; i < 6; i++) {
    await api.post(`/sw-maps/v1/layers/${layer}/features`, {
      geometry: { type: 'Point', coordinates: [8.4 + i * 0.01, 49] },
      properties: { title: `Hut ${i}`, address: 'Weg 1' },
      categories: [cat.id],
    });
  }
  await api.publish(map.id);
  filterPage = await api.createPage('E2E A11y filter page', `[sw_map id="${map.id}"]`);
  await context.close();
});

async function scan(page: Page, root: string, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).include(root).withTags(TAGS).analyze();
  const summary = results.violations.map(
    (v) => `${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes[0]?.target.join(' ')}`,
  );
  expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

test.describe('admin routes', () => {
  test.use({ storageState: authFile('administrator') });

  for (const scheme of SCHEMES) {
    for (const route of ['#/', '#/maps', '#/providers', '#/settings', '#/status']) {
      test(`${route} has no axe violations (${scheme})`, async ({ page, baseURL }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await mockTiles(page, baseURL!);
        await openAdmin(page, route, (p) => p.locator('#sw-maps-app h1'));
        await expect(page.locator('#sw-maps-app h1')).toBeVisible();
        await expect(page.locator('.swmap-skeleton')).toHaveCount(0);
        await scan(page, '#sw-maps-app', `${route} ${scheme}`);
      });
    }

    test(`the map editor has no axe violations (${scheme})`, async ({ page, baseURL }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await mockTiles(page, baseURL!);
      await openAdmin(page, `#/maps/${seeded.mapId}`, (p) =>
        p.locator('.swmap-editor__canvas .ol-viewport'),
      );
      await expect(page.locator('.swmap-editor__canvas .ol-viewport')).toBeVisible();
      await expect(page.locator('[data-test="feature-item"]').first()).toBeVisible();
      await scan(page, '#sw-maps-app', `editor ${scheme}`);
    });
  }

  test('the admin navigation works with the keyboard', async ({ page }) => {
    await openAdmin(page, '#/', (p) => p.locator('#sw-maps-app h1'));
    await expect(page.locator('#sw-maps-app h1')).toBeVisible();
    const link = page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', {
      name: 'Maps',
      exact: true,
    });
    await page.bringToFront();
    // Reach the link the way a keyboard user does: Tab from the skip link through the navigation.
    await page.locator('.swmap-skip').focus();
    for (let i = 0; i < 12 && !(await link.evaluate((el) => el === document.activeElement)); i++) {
      await page.keyboard.press('Tab');
    }
    await expect(link).toBeFocused();
    await link.press('Enter');
    await expect(page).toHaveURL(/#\/maps$/);
    await expect(page.locator('#sw-maps-app h1')).toHaveText('Maps');
  });
});

test.describe('frontend map', () => {
  for (const scheme of SCHEMES) {
    test(`the map with list, legend and search has no axe violations (${scheme})`, async ({
      page,
      baseURL,
    }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await mockTiles(page, baseURL!);
      await page.goto(filterPage);
      await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
      await expect(page.locator('.swmap__chip').first()).toBeVisible();
      await scan(page, '.swmap', `map ${scheme}`);
    });

    test(`an open popup has no axe violations (${scheme})`, async ({ page, baseURL }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await mockTiles(page, baseURL!);
      await page.goto(seeded.shortcodeUrl);
      await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
      await page.locator('.swmap__show').first().click();
      await expect(page.locator('.swmap-popup')).toBeVisible();
      await scan(page, '.swmap', `popup ${scheme}`);
    });
  }

  test('legend, search and list work with the keyboard only', async ({ page, baseURL }) => {
    await mockTiles(page, baseURL!);
    await page.goto(filterPage);
    await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
    const chip = page.locator('.swmap__chip').first();
    await chip.focus();
    await page.keyboard.press('Space');
    await expect(chip).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Space');
    await expect(chip).toHaveAttribute('aria-pressed', 'false');
    const search = page.locator('.swmap__search');
    await search.focus();
    await page.keyboard.type('hut 3');
    await expect(page.locator('.swmap__list li:not([hidden])')).toHaveCount(1);
    const show = page.locator('.swmap__list li:not([hidden]) .swmap__show');
    await show.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.swmap__list li.is-selected')).toHaveCount(1);
    await expect(page.locator('.swmap-popup')).toBeVisible();
    await page.locator('.swmap-popup__close').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.swmap-popup')).toHaveCount(0);
  });
});

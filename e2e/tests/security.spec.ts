import { expect, test } from '@playwright/test';
import { authFile, requireEnv } from '../support/env';
import { mockTiles } from '../support/tiles';
import { seedMap } from '../support/fixtures';
import { WpApi } from '../support/wp';

const rest = (path: string) => `${requireEnv('SWMAPS_E2E_BASE_URL')}/index.php?rest_route=${path}`;

test('drafts and management endpoints are not reachable anonymously', async ({
  request,
  browser,
}) => {
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  const api = await WpApi.create(await context.newPage());
  const draft = await api.createMap('E2E Secret draft');
  await context.close();
  for (const path of [
    '/sw-maps/v1/maps',
    `/sw-maps/v1/maps/${draft.id}`,
    `/sw-maps/v1/maps/${draft.id}/layers`,
    '/sw-maps/v1/providers',
    '/sw-maps/v1/settings',
    '/sw-maps/v1/status',
  ]) {
    const response = await request.get(rest(path));
    expect(response.status(), path).toBe(401);
    expect(await response.text()).not.toContain('Secret draft');
  }
});

test.describe('subscriber', () => {
  test.use({ storageState: authFile('subscriber') });
  test('cannot use the REST API or open the admin page', async ({ page }) => {
    // A logged-in cookie session needs the REST nonce (core provides it to every logged-in user).
    const nonce = await (
      await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')
    ).text();
    const response = await page.request.get(rest('/sw-maps/v1/maps'), {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(response.status()).toBe(403);
    expect((await page.request.get(rest('/sw-maps/v1/maps'))).status()).toBe(401);
    const admin = await page.goto('/wp-admin/admin.php?page=sw-maps');
    expect(admin?.status()).toBeGreaterThanOrEqual(400);
  });
});

test('a draft map renders nothing for visitors but is previewable for editors', async ({
  browser,
  page,
}) => {
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  const api = await WpApi.create(await context.newPage());
  const map = await api.createMap('E2E Preview draft');
  const link = await api.createPage('E2E Draft embed', `[sw_map id="${map.id}"]<p>after</p>`);
  const editorPreview = await (async () => {
    const editorContext = await browser.newContext({ storageState: authFile('editor') });
    const p = await editorContext.newPage();
    await mockTiles(p, requireEnv('SWMAPS_E2E_BASE_URL'));
    await p.goto(link);
    const hasDraft = await p.locator('.swmap__draft').count();
    await editorContext.close();
    return hasDraft;
  })();
  await context.close();
  await page.goto(link);
  await expect(page.getByText('after')).toBeVisible();
  expect(await page.locator('.swmap').count()).toBe(0);
  expect(editorPreview).toBe(1);
});

test('imported markup is shown as text and never executes', async ({ page, browser, baseURL }) => {
  await mockTiles(page, baseURL!);
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  const api = await WpApi.create(await context.newPage());
  const map = await api.createMap('E2E XSS "><svg onload=window.__xss=1>');
  const layer = await api.addLayer(map.id, '<img src=x onerror=window.__xss=1>Layer');
  await api.addMarker(layer, 8.4, 49.0, {
    title: '<img src=x onerror=window.__xss=1>Rathaus',
    description: '"><script>window.__xss=1</script>&lt;b&gt;x&lt;/b&gt;',
    address: '<svg onload=window.__xss=1>Markt',
  });
  await api.publish(map.id);
  const link = await api.createPage('E2E XSS page', `[sw_map id="${map.id}" lazy="0"]`);
  await context.close();

  let dialogs = 0;
  page.on('dialog', async (d) => {
    dialogs += 1;
    await d.dismiss();
  });
  await page.goto(link);
  await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  await page.locator('.swmap__show').first().click();
  await expect(page.locator('.swmap-popup')).toBeVisible();
  expect(
    await page.evaluate(() => (window as unknown as { __xss?: number }).__xss),
  ).toBeUndefined();
  expect(
    await page
      .locator(
        '.swmap img[src="x"], .swmap svg[onload], .swmap script:not([type="application/json"])',
      )
      .count(),
  ).toBe(0);
  expect(dialogs).toBe(0);
  await expect(page.locator('.swmap-popup__text')).toContainText('&lt;b&gt;x&lt;/b&gt;');
});

test('javascript: links are rejected by the API', async ({ browser }) => {
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  const page = await context.newPage();
  const nonce = await (async () => {
    await page.goto('/wp-admin/admin.php?page=sw-maps');
    return page.evaluate(
      () => (window as unknown as { swmapsConfig: { nonce: string } }).swmapsConfig.nonce,
    );
  })();
  const api = await WpApi.create(page);
  const map = await api.createMap('E2E Link');
  const layer = await api.addLayer(map.id);
  const response = await page.request.post(rest(`/sw-maps/v1/layers/${layer}/features`), {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      geometry: { type: 'Point', coordinates: [1, 1] },
      properties: { link: 'javascript:alert(1)' },
    },
  });
  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('swmap_invalid_param');
  await context.close();
  void seedMap;
});

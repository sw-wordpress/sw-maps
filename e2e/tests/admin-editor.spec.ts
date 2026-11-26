import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { mockTiles } from '../support/tiles';
import { WpApi } from '../support/wp';

test.use({ storageState: authFile('administrator') });

const tool = (page: Page, name: string) => page.locator(`[data-test="tool-${name}"]`);

async function openEditorFor(page: Page, title: string): Promise<number> {
  await page.goto(`${PLUGIN_PAGE}#/maps`);
  // On a cold stack the very first admin page can stay blank (the app chunk is still being served): reload once.
  const field = page.locator('input[data-test="new-title"]');
  const shown = await field.waitFor({ state: 'visible', timeout: 10_000 }).then(
    () => true,
    () => false,
  );
  if (!shown) {
    await page.reload();
  }
  await field.fill(title);
  await page.locator('[data-test="create"]').click();
  await expect(page.locator('.swmap-editor__canvas .ol-viewport')).toBeVisible();
  return Number(/maps\/(\d+)/.exec(page.url())?.[1]);
}

test('create a map, place, move and publish a marker', async ({ page, baseURL }) => {
  await mockTiles(page, baseURL!);
  const mapId = await openEditorFor(page, 'E2E Marker');
  const canvas = page.locator('.swmap-editor__canvas');
  const box = (await canvas.boundingBox())!;
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;

  await tool(page, 'point').click();
  await page.mouse.click(x, y);
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(1);
  await expect(page.locator('[data-test="dirty"]')).toBeVisible();
  await page.locator('input[data-test="title"]').fill('Mein Marker');
  await page.locator('input[data-test="title"]').blur();

  const apiPage = await page.context().newPage();
  const api = await WpApi.create(apiPage);
  expect(await api.layers(mapId)).toHaveLength(0); // nothing is saved before the explicit save

  await page.locator('[data-test="save"]').click();
  await expect(page.locator('[data-test="dirty"]')).toHaveCount(0);
  const [layer] = await api.layers(mapId);
  const saved = await api.features(layer!.id);
  expect(saved).toHaveLength(1);
  expect(saved[0]!.properties['title']).toBe('Mein Marker');
  const before = saved[0]!.geometry.coordinates;

  // The save reloads the map and rebuilds the canvas data: let that finish before dragging.
  await page.waitForLoadState('networkidle');

  // Drag the marker with the select tool.
  await tool(page, 'select').click();
  // The page can have scrolled meanwhile: the marker still sits at the centre of the (possibly moved) canvas.
  const now = (await canvas.boundingBox())!;
  const mx = now.x + now.width / 2;
  const my = now.y + now.height / 2;
  // Several pointer moves: the Modify interaction hit-tests the vertex under the pointer on move events.
  await page.mouse.move(mx - 12, my - 12);
  await page.mouse.move(mx, my, { steps: 6 });
  await page.mouse.down();
  await page.mouse.move(mx + 60, my + 40, { steps: 8 });
  await page.mouse.up();
  await expect(page.locator('[data-test="dirty"]')).toBeVisible();
  await page.locator('[data-test="save"]').click();
  await expect(page.locator('[data-test="dirty"]')).toHaveCount(0);
  const moved = (await api.features(layer!.id))[0]!.geometry.coordinates;
  expect(moved[0]).toBeGreaterThan(before[0]!);
  expect(moved[1]).toBeLessThan(before[1]!);

  // Exact coordinates through the form.
  await page.locator('[data-test="feature-item"]').click();
  await page.locator('input[data-test="lon"]').fill('8.5');
  await page.locator('input[data-test="lat"]').fill('49.5');
  await page.locator('input[data-test="lat"]').blur();
  await page.locator('[data-test="save"]').click();
  await expect(page.locator('[data-test="dirty"]')).toHaveCount(0);
  expect((await api.features(layer!.id))[0]!.geometry.coordinates).toEqual([8.5, 49.5]);

  // Publish and view on the frontend.
  await page.locator('[data-test="tab-map"]').click();
  await page.locator('[data-test="status"]').click();
  await page.getByRole('option', { name: 'Published' }).click();
  await page.locator('[data-test="save"]').click();
  await expect(page.locator('[data-test="dirty"]')).toHaveCount(0);
  const link = await api.createPage('E2E Marker page', `[sw_map id="${mapId}"]`);
  await page.goto(link);
  await expect(page.locator('.swmap .ol-viewport canvas')).toBeVisible();
  await expect(page.locator('.swmap__list')).toContainText('Mein Marker');
});

test('undo and redo revert local changes and unsaved changes are guarded', async ({ page }) => {
  await openEditorFor(page, 'E2E Undo');
  const box = (await page.locator('.swmap-editor__canvas').boundingBox())!;
  await tool(page, 'point').click();
  await page.mouse.click(box.x + 100, box.y + 100);
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(1);
  await page.locator('[data-test="undo"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(0);
  await page.locator('[data-test="redo"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(1);

  // Leaving the editor with unsaved changes asks first (declined: stay).
  let message = '';
  page.once('dialog', async (dialog) => {
    message = dialog.message();
    await dialog.dismiss();
  });
  await page.locator('a', { hasText: 'All maps' }).click();
  await expect.poll(() => message).toContain('unsaved changes');
  await expect(page.locator('.swmap-editor__canvas')).toBeVisible();
});

test('mobile preview narrows the canvas', async ({ page }) => {
  await openEditorFor(page, 'E2E Mobile');
  const wide = (await page.locator('.swmap-editor__canvas').boundingBox())!.width;
  await page.locator('[data-test="preview-toggle"] button').nth(1).click();
  await expect
    .poll(async () => (await page.locator('.swmap-editor__canvas').boundingBox())!.width)
    .toBeLessThanOrEqual(376);
  expect(wide).toBeGreaterThan(400);
});

test('the block editor shows a preview of the selected map', async ({ page }) => {
  const api = await WpApi.create(page);
  const map = await api.createMap('E2E Block preview');
  const layer = await api.addLayer(map.id, 'Orte');
  await api.addMarker(layer, 8.4, 49.0, { title: 'Vorschau-Ort' });
  await api.publish(map.id);

  await page.goto('/wp-admin/post-new.php?post_type=page');
  await page.waitForFunction(
    () => (window as unknown as { wp?: { data?: unknown } }).wp?.data !== undefined,
  );
  await page.evaluate((mapId) => {
    type Api = Record<string, (...args: unknown[]) => unknown>;
    const wp = (
      window as unknown as { wp: { data: { dispatch: (store: string) => Api }; blocks: Api } }
    ).wp;
    const call = (target: Api, method: string, ...args: unknown[]): unknown =>
      target[method]!(...args);
    call(wp.data.dispatch('core/preferences'), 'set', 'core/edit-post', 'welcomeGuide', false);
    call(wp.data.dispatch('core/preferences'), 'set', 'core/edit-post', 'fullscreenMode', false);
    const block = call(wp.blocks, 'createBlock', 'sw-maps/map', { mapId });
    call(wp.data.dispatch('core/block-editor'), 'insertBlock', block);
  }, map.id);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const preview = canvas.locator('.wp-block-sw-maps-map .swmap__list');
  await expect(preview).toContainText('Vorschau-Ort', { timeout: 20_000 });
  await expect(canvas.locator('.wp-block-sw-maps-map .swmap__stage')).toBeVisible();
});

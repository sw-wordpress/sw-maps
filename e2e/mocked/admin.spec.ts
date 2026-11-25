import { expect, test } from '@playwright/test';
import { mockApi } from './mockApi';

test('create, edit and delete maps without WordPress', async ({ page }) => {
  const api = await mockApi(page);
  await page.goto('/#/maps');
  await expect(page.getByText('No maps yet.')).toBeVisible();
  await page.locator('input[data-test="new-title"]').fill('Mock map');
  await page.locator('[data-test="create"]').click();
  await expect(page.locator('.swmap-editor__canvas .ol-viewport')).toBeVisible();
  await page.locator('a', { hasText: 'All maps' }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.locator('input[data-test="search"]').fill('nothing');
  await expect(page.getByText('No maps yet.')).toBeVisible();
  await page.locator('input[data-test="search"]').fill('');
  await page.locator('[data-test="duplicate"]').click();
  await expect(page.locator('tbody tr')).toHaveCount(2);
  await page.locator('[data-test="delete"]').first().click();
  await page.locator('[data-test="confirm"]').click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  expect(api.requests).toContain('POST maps/101/duplicate');
});

test('place a marker, save and see it in the layer panel', async ({ page }) => {
  const api = await mockApi(page);
  await page.goto('/#/maps');
  await page.locator('input[data-test="new-title"]').fill('Marker map');
  await page.locator('[data-test="create"]').click();
  const canvas = page.locator('.swmap-editor__canvas');
  await expect(canvas.locator('.ol-viewport')).toBeVisible();
  const box = (await canvas.boundingBox())!;
  await page.locator('[data-test="tool-point"]').click();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(1);
  await page.locator('input[data-test="title"]').fill('Treffpunkt');
  await page.locator('input[data-test="title"]').blur();
  await page.locator('[data-test="save"]').click();
  await expect(page.locator('[data-test="dirty"]')).toHaveCount(0);
  expect(api.state.features).toHaveLength(1);
  expect((api.state.features[0]!['properties'] as Record<string, string>)['title']).toBe(
    'Treffpunkt',
  );
  await page
    .locator('[data-test="undo"]')
    .click()
    .catch(() => undefined);
});

test('enabling a provider shows the external hosts first', async ({ page }) => {
  const api = await mockApi(page);
  await page.goto('/#/providers');
  await page.locator('[data-test="toggle"]').nth(1).click();
  await expect(page.getByRole('dialog')).toContainText('tiles.example.org');
  expect(api.state.providers[1]!['enabled']).toBe(false);
  await page.locator('[data-test="confirm"]').click();
  await expect.poll(() => api.state.providers[1]!['enabled']).toBe(true);
});

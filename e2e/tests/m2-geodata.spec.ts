import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { mockTiles } from '../support/tiles';
import { WpApi } from '../support/wp';

test.use({ storageState: authFile('administrator') });

const POLYGON_WITH_HOLE = [
  [
    [8.3, 48.9],
    [8.5, 48.9],
    [8.5, 49.1],
    [8.3, 49.1],
    [8.3, 48.9],
  ],
  [
    [8.36, 48.96],
    [8.44, 48.96],
    [8.44, 49.04],
    [8.36, 49.04],
    [8.36, 48.96],
  ],
];

const GEOJSON = JSON.stringify({
  type: 'FeatureCollection',
  features: [
    {
      type: 'Feature',
      properties: { name: 'Park', id: 'p1' },
      geometry: { type: 'Polygon', coordinates: POLYGON_WITH_HOLE },
    },
    {
      type: 'Feature',
      properties: { name: 'Hut', id: 'h1' },
      geometry: { type: 'Point', coordinates: [8.4, 49.0] },
    },
  ],
});

const GPX = `<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">
<wpt lat="49.01" lon="8.41"><name>Start</name></wpt>
<trk><name>Rhine trail</name><trkseg><trkpt lat="49.0" lon="8.4"/><trkpt lat="49.05" lon="8.45"/></trkseg></trk></gpx>`;

const CSV = 'Name;Breite;Länge\nKiosk;49,5;8,25\nCafé;49,6;8,35\n';

async function newMap(page: Page, title: string): Promise<{ id: number; api: WpApi }> {
  const api = await WpApi.create(page);
  const map = await api.createMap(title);
  return { id: map.id, api };
}

async function openEditor(page: Page, mapId: number): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/maps/${mapId}`);
  await expect(page.locator('.swmap-editor__canvas .ol-viewport')).toBeVisible();
}

/** The dialog stays open after an import (the report is shown); close it before starting the next one. */
async function closeImport(page: Page): Promise<void> {
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-test="import-dialog"]')).toHaveCount(0);
}

async function chooseFile(page: Page, name: string, content: string): Promise<void> {
  await page.locator('[data-test="import"]').click();
  await page.locator('input[data-test="file"]').setInputFiles({
    name,
    mimeType: 'text/plain',
    buffer: Buffer.from(content),
  });
}

test('import GeoJSON with a polygon hole, keep the hole through the editor, update by external id', async ({
  page,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  const { id, api } = await newMap(page, 'E2E Import GeoJSON');
  await openEditor(page, id);
  await chooseFile(page, 'places.geojson', GEOJSON);
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="summary"]')).toContainText('2 new');
  await page.locator('[data-test="run"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(2);

  const [layer] = await api.layers(id);
  const stored = await api.features(layer!.id);
  const polygon = stored.find((f) => f.properties['title'] === 'Park')!;
  expect((polygon.geometry as unknown as { coordinates: unknown[] }).coordinates).toHaveLength(2);

  // Re-import by external id updates instead of duplicating.
  await closeImport(page);
  await page.locator('[data-test="import"]').click();
  await page.locator('input[data-test="file"]').setInputFiles({
    name: 'places.geojson',
    mimeType: 'text/plain',
    buffer: Buffer.from(GEOJSON.replace('Hut', 'Hut renamed')),
  });
  await page.locator('[data-test="mode"]').click();
  await page.getByRole('option', { name: 'Update by external ID' }).click();
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="summary"]')).toContainText('2 updated');
  await page.locator('[data-test="run"]').click();
  await expect(
    page.locator('[data-test="feature-item"]', { hasText: 'Hut renamed' }),
  ).toBeVisible();
  expect(await api.features(layer!.id)).toHaveLength(2);
});

test('import a GPX track and a CSV with explicit delimiter and decimal comma', async ({
  page,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  const { id, api } = await newMap(page, 'E2E Import GPX CSV');
  await openEditor(page, id);
  await chooseFile(page, 'trail.gpx', GPX);
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="summary"]')).toContainText('2 new');
  await page.locator('[data-test="run"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(2);
  await expect(
    page.locator('[data-test="feature-item"]', { hasText: 'Rhine trail' }),
  ).toContainText('Line');

  await closeImport(page);
  await page.locator('[data-test="import"]').click();
  await page.locator('input[data-test="file"]').setInputFiles({
    name: 'kiosks.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(CSV),
  });
  await page.locator('[data-test="delimiter"]').click();
  await page.getByRole('option', { name: ';' }).click();
  await page.locator('[data-test="decimal"]').click();
  await page.getByRole('option', { name: ',', exact: true }).last().click();
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="summary"]')).toContainText('2 new');
  await page.locator('[data-test="run"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(4);
  const layers = await api.layers(id);
  expect(layers).toHaveLength(2);
});

test('error cases: hostile XML is rejected, invalid entries block the import until skipped', async ({
  page,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  const { id, api } = await newMap(page, 'E2E Import errors');
  await openEditor(page, id);

  const xxe =
    '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx><wpt lat="1" lon="1"><name>&x;</name></wpt></gpx>';
  await chooseFile(page, 'evil.gpx', xxe);
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="failure"]')).toContainText('forbidden XML declarations');
  await expect(page.locator('[data-test="run"]')).toBeDisabled();

  const mixed = JSON.stringify({
    type: 'FeatureCollection',
    features: [
      {
        type: 'Feature',
        properties: { name: 'ok' },
        geometry: { type: 'Point', coordinates: [8, 49] },
      },
      {
        type: 'Feature',
        properties: { name: 'bad' },
        geometry: { type: 'Point', coordinates: [999, 1] },
      },
    ],
  });
  await page.locator('input[data-test="file"]').setInputFiles({
    name: 'mixed.geojson',
    mimeType: 'text/plain',
    buffer: Buffer.from(mixed),
  });
  await page.locator('[data-test="check"]').click();
  await expect(page.locator('[data-test="errors"]')).toContainText('Invalid coordinates or shape.');
  await expect(page.locator('[data-test="run"]')).toBeDisabled();
  await page.locator('[data-test="skip-invalid"]').click();
  await page.locator('[data-test="run"]').click();
  await expect(page.locator('[data-test="feature-item"]')).toHaveCount(1);
  expect(await api.layers(id)).toHaveLength(1);
});

test('visitors filter the list and the map by category and search, and groups cluster', async ({
  page,
  browser,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  const { id, api } = await newMap(page, 'E2E Filter Cluster');
  const layerId = await api.addLayer(id, 'Orte');
  const huts = await api.post<{ id: number }>('/sw-maps/v1/categories', {
    title: 'E2E Huts',
    style: { color: '#007700', icon: 'builtin:home' },
  });
  const lakes = await api.post<{ id: number }>('/sw-maps/v1/categories', { title: 'E2E Lakes' });
  const point = (lon: number, lat: number, title: string, categories: number[]) =>
    api.post(`/sw-maps/v1/layers/${layerId}/features`, {
      geometry: { type: 'Point', coordinates: [lon, lat] },
      properties: { title },
      categories,
    });
  for (let i = 0; i < 6; i++) await point(8.4 + i * 0.00002, 49.0, `Hut ${i}`, [huts.id]);
  await point(8.9, 49.5, 'Blue lake', [lakes.id]);
  await api.updateMap(id, { viewport: { center: [8.4, 49.0], zoom: 9, autoBounds: false } });
  await api.publish(id);
  const url = await api.createPage('E2E Filter page', `[sw_map id="${id}"]`);

  const visitor = await browser.newContext({ baseURL });
  const view = await visitor.newPage();
  await mockTiles(view, baseURL!);
  await view.goto(url);
  await expect(view.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  await expect(view.locator('.swmap__list li:not([hidden])')).toHaveCount(7);

  // Category chips carry the marker shape as an image, not only a colour.
  const chip = view.locator('.swmap__chip', { hasText: 'E2E Lakes' });
  await expect(chip.locator('img')).toHaveAttribute('src', /^data:image\/svg\+xml/);
  await chip.click();
  await expect(chip).toHaveAttribute('aria-pressed', 'true');
  await expect(view.locator('.swmap__list li:not([hidden])')).toHaveCount(1);
  await expect(view.locator('.swmap__list li:not([hidden])')).toContainText('Blue lake');
  await chip.click();
  await view.locator('.swmap__search').fill('hut 3');
  await expect(view.locator('.swmap__list li:not([hidden])')).toHaveCount(1);
  await view.locator('.swmap__search').fill('nothing matches');
  await expect(view.locator('.swmap__empty')).toHaveText('No locations found.');
  await view.locator('.swmap__search').fill('');
  await expect(view.locator('.swmap__list li:not([hidden])')).toHaveCount(7);

  // The six huts form one cluster; clicking it zooms in (the scale line changes).
  const scale = view.locator('.ol-scale-line-inner');
  const before = await scale.innerText();
  await view.locator('.swmap .ol-viewport').click();
  await expect.poll(async () => scale.innerText()).not.toBe(before);
  await visitor.close();
});

test('maps that load locations on demand request only the visible area', async ({
  page,
  browser,
  baseURL,
}) => {
  await mockTiles(page, baseURL!);
  const { id, api } = await newMap(page, 'E2E Ajax');
  const layerId = await api.addLayer(id, 'Orte');
  await api.addMarker(layerId, 8.4, 49.0, { title: 'Near' });
  await api.addMarker(layerId, 2.35, 48.85, { title: 'Far away' });
  await api.updateMap(id, {
    viewport: { center: [8.4, 49.0], zoom: 11, autoBounds: false },
    settings: { ajax: true },
  });
  await api.publish(id);
  const url = await api.createPage('E2E Ajax page', `[sw_map id="${id}"]`);

  const visitor = await browser.newContext({ baseURL });
  const view = await visitor.newPage();
  await mockTiles(view, baseURL!);
  const requests: string[] = [];
  view.on('request', (r) => {
    if (r.url().includes('public/maps')) requests.push(r.url());
  });
  await view.goto(url);
  await expect(view.locator('.swmap')).toHaveAttribute('data-state', 'ready');
  await expect.poll(() => requests.length).toBeGreaterThan(0);
  expect(requests[0]).toContain('bbox=');
  expect(decodeURIComponent(requests[0]!)).toMatch(/bbox=[-\d.]+,[-\d.]+,[-\d.]+,[-\d.]+/);
  // The inline payload carries no locations in this mode.
  const inline = await view.locator('script.swmap__data').evaluate((el) => el.textContent ?? '');
  expect(inline).not.toContain('Far away');
  expect(inline).not.toContain('Near');
  await visitor.close();
});

import { expect, test } from '@playwright/test';
import type { Browser } from '@playwright/test';
import { authFile, requireEnv } from '../support/env';
import { mockTiles } from '../support/tiles';
import { WpApi } from '../support/wp';

/**
 * Browser benchmarks (M3-07). Skipped unless SWMAPS_BENCH=1; results are printed as a Markdown table:
 *   SWMAPS_BENCH=1 bin/test-e2e.sh -- tests/benchmark.spec.ts --retries 0
 */
test.skip(process.env['SWMAPS_BENCH'] !== '1', 'Set SWMAPS_BENCH=1 to run the benchmarks.');

const SIZES = [100, 1000, 10000];
const pages = new Map<number, string>();

async function seed(browser: Browser, count: number): Promise<string> {
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  const page = await context.newPage();
  const api = await WpApi.create(page);
  const map = await api.createMap(`E2E Bench ${count}`);
  const features = Array.from({ length: count }, (_, i) => ({
    type: 'Feature',
    properties: { name: `Place ${i}`, address: `Street ${i}` },
    geometry: {
      type: 'Point',
      coordinates: [5 + (i % 1000) * 0.01, 45 + Math.floor(i / 1000) * 0.01 + (i % 7) * 0.001],
    },
  }));
  await api.post('/sw-maps/v1/imports', {
    map_id: map.id,
    format: 'geojson',
    content: JSON.stringify({ type: 'FeatureCollection', features }),
    layer_title: 'Points',
  });
  await api.updateMap(map.id, { viewport: { center: [10, 45.1], zoom: 6, autoBounds: false } });
  await api.publish(map.id);
  const url = await api.createPage(`E2E Bench ${count} page`, `[sw_map id="${map.id}"]`);
  await context.close();
  return url;
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  const page = await context.newPage();
  const api = await WpApi.create(page);
  await api.patchSettings({ max_features_per_layer: 50000 });
  await context.close();
  for (const n of SIZES) pages.set(n, await seed(browser, n));
});

test('map load and interaction with 100 / 1,000 / 10,000 points', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);
  const rows = [
    '| points | HTML + JSON transfer | map ready | list filter (search) | heap after load |',
    '|---:|---:|---:|---:|---:|',
  ];
  for (const n of SIZES) {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    await mockTiles(page, baseURL!);
    let bytes = 0;
    page.on('response', async (r) => {
      if (r.url() === pages.get(n)) bytes = (await r.body()).length;
    });
    const start = Date.now();
    await page.goto(pages.get(n)!);
    await expect(page.locator('.swmap')).toHaveAttribute('data-state', 'ready', {
      timeout: 60_000,
    });
    const ready = Date.now() - start;
    const search = page.locator('.swmap__search');
    let filter = 'n/a (no search field below 5 points)';
    if (await search.count()) {
      // Synchronous work of the input handler plus two animation frames (layout and map render).
      const ms = await page.evaluate(
        async (text) => {
          const input = document.querySelector<HTMLInputElement>('.swmap__search')!;
          const t0 = performance.now();
          input.value = text;
          input.dispatchEvent(new Event('input'));
          await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
          return performance.now() - t0;
        },
        `Place ${n - 1}`,
      );
      await expect(page.locator('.swmap__list li:not([hidden])')).toHaveCount(1, {
        timeout: 60_000,
      });
      filter = `${Math.round(ms)} ms`;
    }
    const heap = await page.evaluate(
      () =>
        ((performance as unknown as { memory?: { usedJSHeapSize: number } }).memory
          ?.usedJSHeapSize ?? 0) / 1048576,
    );
    rows.push(
      `| ${n} | ${Math.round(bytes / 1024)} KB | ${ready} ms | ${filter} | ${heap.toFixed(0)} MB |`,
    );
    await context.close();
  }
  process.stdout.write('\n' + rows.join('\n') + '\n');
});

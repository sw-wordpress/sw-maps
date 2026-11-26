import type { Page, Request } from '@playwright/test';

/** 1x1 transparent PNG used for every mocked tile. */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);

export interface NetworkLog {
  /** Requests to hosts other than the WordPress site (everything that would leave the server). */
  external: string[];
  /** Tile requests (mocked). */
  tiles: string[];
  /** Requests for the plugin's frontend bundle. */
  bundle: string[];
}

/**
 * Mocks the public OpenStreetMap tile server (CI never depends on it) and records every request leaving the site, so
 * tests can assert on privacy: nothing external before consent, no bundle on pages without a map.
 */
export async function mockTiles(page: Page, siteOrigin: string): Promise<NetworkLog> {
  const log: NetworkLog = { external: [], tiles: [], bundle: [] };
  await page.route(/^https?:\/\/(?!localhost|127\.0\.0\.1)/, async (route) => {
    const url = route.request().url();
    log.external.push(url);
    if (/tile\.openstreetmap\.org|tiles\.example\.org/.test(url)) {
      log.tiles.push(url);
      await route.fulfill({
        status: 200,
        contentType: 'image/png',
        body: PNG,
        headers: { 'access-control-allow-origin': '*' },
      });
    } else {
      await route.abort();
    }
  });
  page.on('request', (request: Request) => {
    if (request.url().includes('swmaps-viewer.js')) log.bundle.push(request.url());
  });
  void siteOrigin;
  return log;
}

export function tileHostsOnly(log: NetworkLog): boolean {
  return log.external.every((u) => /tile\.openstreetmap\.org|tiles\.example\.org/.test(u));
}

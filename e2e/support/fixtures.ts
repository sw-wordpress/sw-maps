import type { Browser } from '@playwright/test';
import { authFile, requireEnv } from './env';
import { WpApi } from './wp';

export interface SeededMap {
  mapId: number;
  layerId: number;
  /** Published page embedding the map by shortcode. */
  shortcodeUrl: string;
  /** Published page embedding the map with the Gutenberg block. */
  blockUrl: string;
}

/** Creates a published map with two markers plus pages embedding it (shortcode and block) through the real REST API. */
export async function seedMap(browser: Browser, title: string): Promise<SeededMap> {
  const context = await browser.newContext({
    storageState: authFile('administrator'),
    baseURL: requireEnv('SWMAPS_E2E_BASE_URL'),
  });
  try {
    const page = await context.newPage();
    const api = await WpApi.create(page);
    const map = await api.createMap(title);
    const layerId = await api.addLayer(map.id, 'Orte');
    await api.addMarker(layerId, 8.4037, 49.0069, {
      title: 'Schloss',
      address: 'Schlossbezirk 10',
      link: 'https://example.org/schloss',
    });
    await api.addMarker(layerId, 8.4211, 49.0094, {
      title: 'Hauptbahnhof',
      description: 'Ankunft & Abfahrt',
    });
    await api.publish(map.id);
    const shortcodeUrl = await api.createPage(`${title} shortcode`, `[sw_map id="${map.id}"]`);
    const blockUrl = await api.createPage(
      `${title} block`,
      `<!-- wp:sw-maps/map {"mapId":${map.id}} /-->`,
    );
    return { mapId: map.id, layerId, shortcodeUrl, blockUrl };
  } finally {
    await context.close();
  }
}

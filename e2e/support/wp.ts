import type { APIRequestContext, Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { PLUGIN_PAGE } from './env';

/** REST helper authenticated like the admin app (cookie + nonce taken from the plugin's own admin page). */
export class WpApi {
  private constructor(
    private readonly request: APIRequestContext,
    private readonly origin: string,
    private readonly nonce: string,
  ) {}

  static async create(page: Page): Promise<WpApi> {
    await page.goto(PLUGIN_PAGE);
    const nonce = await page.evaluate(
      () => (window as unknown as { swmapsConfig: { nonce: string } }).swmapsConfig.nonce,
    );
    return new WpApi(page.request, new URL(page.url()).origin, nonce);
  }

  private async call<T>(method: string, path: string, data?: unknown): Promise<T> {
    const response = await this.request.fetch(`${this.origin}/index.php?rest_route=${path}`, {
      method,
      headers: { 'X-WP-Nonce': this.nonce },
      data: data as never,
    });
    expect(response.ok(), `${method} ${path}: ${response.status()} ${await response.text()}`).toBe(
      true,
    );
    return response.status() === 204 ? (undefined as T) : ((await response.json()) as T);
  }

  async createMap(
    title: string,
    extra: Record<string, unknown> = {},
  ): Promise<{ id: number; slug: string }> {
    const map = await this.call<{ id: number; slug: string }>('POST', '/sw-maps/v1/maps', {
      title,
      ...extra,
    });
    return map;
  }

  async publish(mapId: number): Promise<void> {
    await this.call('PATCH', `/sw-maps/v1/maps/${mapId}`, { status: 'published' });
  }

  async updateMap(mapId: number, patch: Record<string, unknown>): Promise<void> {
    await this.call('PATCH', `/sw-maps/v1/maps/${mapId}`, patch);
  }

  async addLayer(
    mapId: number,
    title = 'Layer',
    extra: Record<string, unknown> = {},
  ): Promise<number> {
    return (
      await this.call<{ id: number }>('POST', `/sw-maps/v1/maps/${mapId}/layers`, {
        title,
        ...extra,
      })
    ).id;
  }

  async addMarker(
    layerId: number,
    lon: number,
    lat: number,
    properties: Record<string, unknown> = {},
  ): Promise<number> {
    return (
      await this.call<{ id: number }>('POST', `/sw-maps/v1/layers/${layerId}/features`, {
        geometry: { type: 'Point', coordinates: [lon, lat] },
        properties,
      })
    ).id;
  }

  async features(
    layerId: number,
  ): Promise<
    Array<{ id: number; geometry: { coordinates: number[] }; properties: Record<string, string> }>
  > {
    return this.call('GET', `/sw-maps/v1/layers/${layerId}/features`);
  }

  async layers(mapId: number): Promise<Array<{ id: number }>> {
    return this.call('GET', `/sw-maps/v1/maps/${mapId}/layers`);
  }

  async createPage(title: string, content: string): Promise<string> {
    const page = await this.call<{ link: string }>('POST', '/wp/v2/pages', {
      title,
      content,
      status: 'publish',
    });
    return page.link;
  }

  async deleteMap(mapId: number): Promise<void> {
    await this.call('DELETE', `/sw-maps/v1/maps/${mapId}`);
  }
}

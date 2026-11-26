import type { BoundsTuple, MapRenderData, PublicFeature } from '@sw-maps/map-contracts';

interface PageResponse {
  layers?: Array<{ id: number; features: PublicFeature[] }>;
  total_pages?: number;
}

export interface AjaxLoaderOptions {
  url: string;
  /** Wait after the last view change before requesting (ms). */
  debounceMs?: number;
  /** Upper bound of requested pages per view (bounds the work for a very dense area). */
  maxPages?: number;
  perPage?: number;
  fetchImpl?: typeof fetch;
  onData: (data: MapRenderData) => void;
  onError: (error: unknown) => void;
}

/**
 * Loads the features of the visible area on demand. A new request (view change or filter change) aborts the one in
 * flight and is debounced, so panning never queues up requests. The base data (layers without features) is the
 * template; every response replaces the feature lists.
 */
export class AjaxLoader {
  private timer: ReturnType<typeof setTimeout> | undefined;
  private controller: AbortController | null = null;
  private destroyed = false;

  constructor(
    private readonly base: MapRenderData,
    private readonly options: AjaxLoaderOptions,
  ) {}

  /** Requests the area after the debounce time. */
  request(bounds: BoundsTuple, zoom: number, categories: ReadonlySet<number>): void {
    clearTimeout(this.timer);
    this.timer = setTimeout(
      () => void this.load(bounds, zoom, categories),
      this.options.debounceMs ?? 250,
    );
  }

  /** Requests the area right away (first load). */
  requestNow(bounds: BoundsTuple, zoom: number, categories: ReadonlySet<number>): void {
    clearTimeout(this.timer);
    void this.load(bounds, zoom, categories);
  }

  destroy(): void {
    this.destroyed = true;
    clearTimeout(this.timer);
    this.controller?.abort();
  }

  private async load(
    bounds: BoundsTuple,
    zoom: number,
    categories: ReadonlySet<number>,
  ): Promise<void> {
    this.controller?.abort();
    const controller = new AbortController();
    this.controller = controller;
    const fetchImpl = this.options.fetchImpl ?? ((...args) => fetch(...args));
    const byLayer = new Map<number, PublicFeature[]>();
    try {
      const maxPages = this.options.maxPages ?? 5;
      for (let page = 1; page <= maxPages; page++) {
        const query = new URLSearchParams({
          bbox: bounds.map((v) => v.toFixed(6)).join(','),
          zoom: zoom.toFixed(2),
          page: String(page),
          per_page: String(this.options.perPage ?? 500),
        });
        if (categories.size > 0) query.set('categories', [...categories].join(','));
        // Plain permalinks put the route in the query string (`?rest_route=`), so "?" may already be present.
        const target = `${this.options.url}${this.options.url.includes('?') ? '&' : '?'}${query}`;
        const response = await fetchImpl(target, {
          signal: controller.signal,
          headers: { Accept: 'application/json' },
          credentials: 'omit',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body = (await response.json()) as PageResponse;
        for (const layer of body.layers ?? []) {
          byLayer.set(layer.id, [...(byLayer.get(layer.id) ?? []), ...layer.features]);
        }
        if (page >= (body.total_pages ?? 1)) break;
      }
    } catch (error) {
      if (controller.signal.aborted) return; // superseded by a newer request
      if (!this.destroyed) this.options.onError(error);
      return;
    }
    if (controller.signal.aborted || this.destroyed) return;
    this.options.onData({
      ...this.base,
      layers: this.base.layers.map((l) => ({ ...l, features: byLayer.get(l.id) ?? [] })),
    });
  }
}

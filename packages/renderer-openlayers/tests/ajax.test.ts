import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BoundsTuple, MapRenderData } from '@sw-maps/map-contracts';
import { AjaxLoader } from '../src/ajax';
import { MapViewer, OpenLayersRenderer } from '../src';
import { feature, makeData } from './fixtures';

const bounds: BoundsTuple = [8, 49, 9, 50];
const json = (body: unknown, ok = true) =>
  ({ ok, status: ok ? 200 : 500, json: async () => body }) as Response;

function base(): MapRenderData {
  const data = makeData();
  data.layers = [
    { id: 1, title: 'A', style: {}, features: [] },
    { id: 2, title: 'B', style: {}, features: [] },
  ];
  return data;
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('AjaxLoader', () => {
  it('debounces requests, sends bbox, zoom and categories and merges the layers', async () => {
    const fetchImpl = vi.fn(async () =>
      json({ layers: [{ id: 2, features: [feature(7)] }], total_pages: 1 }),
    );
    const onData = vi.fn();
    const loader = new AjaxLoader(base(), {
      url: 'https://x.test/wp-json/sw-maps/v1/public/maps/1/features',
      onData,
      onError: vi.fn(),
      fetchImpl: fetchImpl as never,
    });
    loader.request(bounds, 9.5, new Set([3, 4]));
    loader.request(bounds, 10, new Set([3, 4]));
    await vi.advanceTimersByTimeAsync(249);
    expect(fetchImpl).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(5);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const url = new URL((fetchImpl.mock.calls[0] as unknown as [string])[0]);
    expect(Object.fromEntries(url.searchParams)).toEqual({
      bbox: '8.000000,49.000000,9.000000,50.000000',
      zoom: '10.00',
      page: '1',
      per_page: '500',
      categories: '3,4',
    });
    const result = onData.mock.calls[0]![0] as MapRenderData;
    expect(result.layers.map((l) => l.features.length)).toEqual([0, 1]);
    expect(result.id).toBe(base().id);
  });

  it('works with plain permalinks (url already has a query) and loads further pages up to the limit', async () => {
    const fetchImpl = vi.fn(async (target: string) => {
      const page = Number(new URL(target).searchParams.get('page'));
      return json({ layers: [{ id: 1, features: [feature(page)] }], total_pages: 9 });
    });
    const onData = vi.fn();
    const loader = new AjaxLoader(base(), {
      url: 'https://x.test/?rest_route=/sw-maps/v1/public/maps/1/features',
      maxPages: 3,
      onData,
      onError: vi.fn(),
      fetchImpl: fetchImpl as never,
    });
    loader.requestNow(bounds, 5, new Set());
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchImpl).toHaveBeenCalledTimes(3);
    expect(String(fetchImpl.mock.calls[0]![0])).toContain(
      '?rest_route=/sw-maps/v1/public/maps/1/features&bbox=',
    );
    expect(String(fetchImpl.mock.calls[0]![0])).not.toContain('categories');
    expect((onData.mock.calls[0]![0] as MapRenderData).layers[0]!.features).toHaveLength(3);
  });

  it('aborts the request in flight when a newer one starts and drops its result', async () => {
    const signals: AbortSignal[] = [];
    const fetchImpl = vi.fn((_: string, init: RequestInit) => {
      signals.push(init.signal as AbortSignal);
      return new Promise<Response>((resolve) =>
        setTimeout(() => resolve(json({ layers: [] })), 100),
      );
    });
    const onData = vi.fn();
    const loader = new AjaxLoader(base(), {
      url: '/x',
      onData,
      onError: vi.fn(),
      fetchImpl: fetchImpl as never,
    });
    loader.requestNow(bounds, 5, new Set());
    await vi.advanceTimersByTimeAsync(10);
    loader.requestNow(bounds, 6, new Set());
    expect(signals[0]!.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(200);
    expect(onData).toHaveBeenCalledTimes(1);
  });

  it('reports failures except for aborted or destroyed loaders', async () => {
    const onError = vi.fn();
    const bad = new AjaxLoader(base(), {
      url: '/x',
      onData: vi.fn(),
      onError,
      fetchImpl: (async () => json({}, false)) as never,
    });
    bad.requestNow(bounds, 5, new Set());
    await vi.advanceTimersByTimeAsync(0);
    expect(onError).toHaveBeenCalledTimes(1);
    const rejects = new AjaxLoader(base(), {
      url: '/x',
      onData: vi.fn(),
      onError,
      fetchImpl: (async () => {
        throw new Error('offline');
      }) as never,
    });
    rejects.requestNow(bounds, 5, new Set());
    await vi.advanceTimersByTimeAsync(0);
    expect(onError).toHaveBeenCalledTimes(2);
    const gone = new AjaxLoader(base(), {
      url: '/x',
      onData: vi.fn(),
      onError,
      fetchImpl: (async () => json({}, false)) as never,
    });
    gone.request(bounds, 5, new Set());
    gone.destroy();
    await vi.advanceTimersByTimeAsync(500);
    expect(onError).toHaveBeenCalledTimes(2);
    const racing = new AjaxLoader(base(), {
      url: '/x',
      onData: vi.fn(),
      onError,
      fetchImpl: (async () => json({ layers: [] })) as never,
    });
    racing.requestNow(bounds, 5, new Set());
    racing.destroy();
    await vi.advanceTimersByTimeAsync(0);
    expect(onError).toHaveBeenCalledTimes(2);
  });
});

describe('viewer in AJAX mode', () => {
  it('loads the visible area on start, on view changes and on filter changes', async () => {
    const data = base();
    data.ajaxUrl = 'https://x.test/wp-json/sw-maps/v1/public/maps/1/features';
    data.categories = [{ id: 3, title: 'Huts', style: {} }];
    document.body.innerHTML = `<div class="swmap" data-swmap="1" data-lazy="0"><div class="swmap__stage"></div><script type="application/json" class="swmap__data">${JSON.stringify(data)}</script></div>`;
    const fetchMock = vi.fn(async () =>
      json({ layers: [{ id: 1, features: [feature(1)] }], total_pages: 1 }),
    );
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('IntersectionObserver', undefined);
    const renderer = new OpenLayersRenderer({ popups: true });
    const update = vi.spyOn(renderer, 'update');
    const root = document.querySelector<HTMLElement>('.swmap')!;
    const viewer = new MapViewer(root, {
      createRenderer: () => renderer,
      hasConsent: () => true,
      onConsentChange: () => () => undefined,
    });
    viewer.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(update).toHaveBeenCalledTimes(1);
    renderer.setView({ zoom: 12 });
    (renderer as unknown as { map: { dispatchEvent(e: string): void } }).map.dispatchEvent(
      'moveend',
    );
    await vi.advanceTimersByTimeAsync(300);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    root.querySelector<HTMLButtonElement>('.swmap__chip')!.click();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(String((fetchMock.mock.calls[2] as unknown as [string])[0])).toContain('categories=3');
    viewer.destroy();
    expect(root.querySelector('.swmap__filters')).toBeNull();
  });

  it('shows the error notice when loading fails and stops loading after consent withdrawal', async () => {
    const data = base();
    data.ajaxUrl = '/x';
    data.consentCategory = 'marketing';
    document.body.innerHTML = `<div class="swmap" data-swmap="1" data-lazy="0"><div class="swmap__stage"></div><script type="application/json" class="swmap__data">${JSON.stringify(data)}</script></div>`;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json({}, false)),
    );
    vi.stubGlobal('IntersectionObserver', undefined);
    let consent = true;
    let notify: () => void = () => undefined;
    const viewer = new MapViewer(document.querySelector<HTMLElement>('.swmap')!, {
      createRenderer: () => new OpenLayersRenderer({ popups: true }),
      hasConsent: () => consent,
      onConsentChange: (cb) => {
        notify = cb;
        return () => undefined;
      },
    });
    viewer.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(document.querySelector('.swmap__notice')).not.toBeNull();
    consent = false;
    notify();
    expect(viewer.currentState).toBe('waiting-consent');
  });
});

describe('renderer bounds', () => {
  it('reports the visible area in WGS84, clamped to valid values', () => {
    document.body.innerHTML = '<div class="swmap__stage" style="width:600px;height:400px"></div>';
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(document.querySelector<HTMLElement>('.swmap__stage')!, { data: makeData() });
    const [minLon, minLat, maxLon, maxLat] = r.getBounds();
    for (const v of [minLon, maxLon]) expect(Math.abs(v)).toBeLessThanOrEqual(180);
    for (const v of [minLat, maxLat]) expect(Math.abs(v)).toBeLessThanOrEqual(90);
    expect(minLon).toBeLessThanOrEqual(maxLon);
    expect(minLat).toBeLessThanOrEqual(maxLat);
  });
});

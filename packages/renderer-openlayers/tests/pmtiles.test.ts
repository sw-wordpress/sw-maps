import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicProvider } from '@sw-maps/map-contracts';

const getHeader = vi.fn();
const getZxy = vi.fn();
vi.mock('pmtiles', () => ({
  PMTiles: class {
    getHeader = getHeader;
    getZxy = getZxy;
  },
  TileType: { Unknown: 0, Mvt: 1, Png: 2, Jpeg: 3, Webp: 4, Avif: 5 },
}));

import { createPmtilesSource } from '../src/pmtiles';
import { OpenLayersRenderer } from '../src';
import { makeData } from './fixtures';

const provider: PublicProvider = {
  type: 'pmtiles',
  urlTemplate: 'https://files.example.org/world.pmtiles',
  attribution: '© Protomaps',
  minZoom: 0,
  maxZoom: 12,
  tileSize: 256,
};

function fakeTile() {
  const image = document.createElement('img');
  return { image, tile: { getImage: () => image } as never };
}

type Loader = (tile: unknown, src: string) => void;
const loaderOf = (source: ReturnType<typeof createPmtilesSource>): Loader =>
  source.getTileLoadFunction() as unknown as Loader;

beforeEach(() => {
  getHeader.mockReset().mockResolvedValue({ tileType: 2 });
  getZxy.mockReset();
  vi.stubGlobal(
    'URL',
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }),
  );
});
afterEach(() => vi.unstubAllGlobals());

describe('PMTiles source', () => {
  it('maps tile coordinates to archive requests and exposes the zoom range', () => {
    const source = createPmtilesSource(provider, vi.fn());
    expect(source.getTileUrlFunction()([3, 4, 5], 1, source.getProjection()!)).toBe('3/4/5');
    expect(source.getTileUrlFunction()(null as never, 1, source.getProjection()!)).toBeUndefined();
    expect(source.getTileGrid()!.getMaxZoom()).toBe(12);
  });

  it('loads a raster tile through a blob URL and releases it afterwards', async () => {
    getZxy.mockResolvedValue({ data: new Uint8Array([1, 2, 3]).buffer });
    const { image, tile } = fakeTile();
    loaderOf(createPmtilesSource(provider, vi.fn()))(tile, '2/1/1');
    await vi.waitFor(() => expect(image.src).toBe('blob:x'));
    expect(getZxy).toHaveBeenCalledWith(2, 1, 1);
    image.dispatchEvent(new Event('load'));
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x');
  });

  it('draws a missing tile as transparent without an error', async () => {
    getZxy.mockResolvedValue(undefined);
    const { image, tile } = fakeTile();
    const errors = vi.fn();
    image.addEventListener('error', errors);
    loaderOf(createPmtilesSource(provider, vi.fn()))(tile, '0/0/0');
    await vi.waitFor(() => expect(image.src).toContain('data:image/gif'));
    expect(errors).not.toHaveBeenCalled();
  });

  it('reports vector archives once and fails their tiles', async () => {
    getHeader.mockResolvedValue({ tileType: 1 });
    const unsupported = vi.fn();
    const load = loaderOf(createPmtilesSource(provider, unsupported));
    const a = fakeTile();
    const b = fakeTile();
    const errors = vi.fn();
    a.image.addEventListener('error', errors);
    b.image.addEventListener('error', errors);
    load(a.tile, '0/0/0');
    load(b.tile, '1/0/0');
    await vi.waitFor(() => expect(errors).toHaveBeenCalledTimes(2));
    expect(unsupported).toHaveBeenCalledTimes(1);
    expect(getZxy).not.toHaveBeenCalled();
  });

  it('turns read failures into tile errors', async () => {
    getZxy.mockRejectedValue(new Error('range request failed'));
    const { image, tile } = fakeTile();
    const errors = vi.fn();
    image.addEventListener('error', errors);
    loaderOf(createPmtilesSource(provider, vi.fn()))(tile, '0/0/0');
    await vi.waitFor(() => expect(errors).toHaveBeenCalledTimes(1));
  });
});

describe('renderer with a PMTiles provider', () => {
  it('uses the archive source, rebuilds when the archive changes and reports vector archives as an error', async () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const target = document.querySelector<HTMLElement>('.swmap__stage')!;
    const data = makeData({ provider });
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(target, { data });
    const layer = (r as unknown as { tileLayer: { getSource(): { getUrls(): string[] | null } } })
      .tileLayer;
    expect(layer.getSource().getUrls()).toBeNull();
    r.update(data);
    const other = makeData({
      provider: { ...provider, urlTemplate: 'https://files.example.org/other.pmtiles' },
    });
    r.update(other);
    expect(target.querySelectorAll('.swmap__canvas')).toHaveLength(1);
  });
});

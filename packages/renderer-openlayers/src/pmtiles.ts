import { PMTiles, TileType } from 'pmtiles';
import XYZ from 'ol/source/XYZ.js';
import type ImageTile from 'ol/ImageTile.js';
import type Tile from 'ol/Tile.js';
import type { PublicProvider } from '@sw-maps/map-contracts';

/** 1x1 transparent pixel for tiles the archive does not contain (not an error). */
const EMPTY_TILE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const MIME: Partial<Record<TileType, string>> = {
  [TileType.Png]: 'image/png',
  [TileType.Jpeg]: 'image/jpeg',
  [TileType.Webp]: 'image/webp',
  [TileType.Avif]: 'image/avif',
};

const closers = new WeakMap<XYZ, () => void>();

/** Stops a PMTiles source: tiles that are still waiting for a read are dropped and no new range request starts. */
export function closePmtiles(source: unknown): void {
  closers.get(source as XYZ)?.();
}

/**
 * Raster tile source backed by one PMTiles archive (HTTP range requests, no tile server). Vector archives are not
 * rendered: `onUnsupported` is called once and no tile is drawn.
 */
export function createPmtilesSource(provider: PublicProvider, onUnsupported: () => void): XYZ {
  const archive = new PMTiles(provider.urlTemplate);
  let tileType: Promise<TileType> | null = null;
  let reported = false;
  let closed = false;
  const typeOf = (): Promise<TileType> =>
    (tileType ??= archive.getHeader().then((h) => h.tileType));

  const load = async (tile: Tile, src: string): Promise<void> => {
    const image = (tile as ImageTile).getImage() as HTMLImageElement;
    const [z, x, y] = src.split('/').map(Number) as [number, number, number];
    if (closed) throw new Error('Closed');
    const type = await typeOf();
    if (closed) throw new Error('Closed');
    const mime = MIME[type];
    if (!mime) {
      if (!reported) {
        reported = true;
        onUnsupported();
      }
      throw new Error('Vector tiles are not supported.');
    }
    const result = await archive.getZxy(z, x, y);
    if (closed) throw new Error('Closed');
    if (!result) {
      image.src = EMPTY_TILE;
      return;
    }
    const url = URL.createObjectURL(new Blob([result.data], { type: mime }));
    const release = (): void => URL.revokeObjectURL(url);
    image.addEventListener('load', release, { once: true });
    image.addEventListener('error', release, { once: true });
    image.src = url;
  };

  const source = new XYZ({
    minZoom: provider.minZoom,
    maxZoom: provider.maxZoom,
    tileSize: provider.tileSize,
    attributions: [],
    crossOrigin: 'anonymous',
    tileUrlFunction: (coord) => (coord ? `${coord[0]}/${coord[1]}/${coord[2]}` : undefined),
    tileLoadFunction: (tile, src) => {
      load(tile, src).catch(() => {
        // A failed read counts as a tile error (the renderer reports repeated failures).
        (tile as ImageTile).getImage()?.dispatchEvent(new Event('error'));
      });
    },
  });
  closers.set(source, () => {
    closed = true;
  });
  return source;
}

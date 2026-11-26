import { Circle as CircleStyle, Fill, Icon, Stroke, Style, Text } from 'ol/style.js';
import type { StyleLike } from 'ol/style/Style.js';
import type FeatureLike from 'ol/Feature.js';
import { safeColor } from '@sw-maps/map-contracts';
import type { FeatureProperties, PublicLayer, StyleMap } from '@sw-maps/map-contracts';
import { DEFAULT_COLOR, resolveIcon } from './markers';

export interface FeatureMeta {
  id: number;
  layer: PublicLayer;
  properties: FeatureProperties;
  style: StyleMap;
}

export function hexToRgba(hex: string, alpha: number): string {
  let h = hex.slice(1);
  if (h.length === 3 || h.length === 4) h = [...h].map((c) => c + c).join('');
  const n = Number.parseInt(h.slice(0, 6), 16);
  const a = Math.min(1, Math.max(0, alpha));
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
}

function numberStyle(value: unknown, min: number, max: number, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.min(max, Math.max(min, value))
    : fallback;
}

/** Creates the OpenLayers style function. `selectedId` is read lazily so selection needs no style rebuild. */
export function createStyleFunction(
  metaOf: (feature: FeatureLike) => FeatureMeta | undefined,
  selectedId: () => number | null,
): StyleLike {
  const cache = new Map<string, Style>();
  const remember = (key: string, make: () => Style): Style => {
    let style = cache.get(key);
    if (!style) {
      style = make();
      cache.set(key, style);
    }
    return style;
  };

  return (feature) => {
    const meta = metaOf(feature as FeatureLike);
    const geometry = feature.getGeometry();
    if (!meta || !geometry) return undefined;
    const selected = selectedId() === meta.id;
    const color = safeColor(meta.style['color'] ?? meta.layer.style['color'], DEFAULT_COLOR);
    const type = geometry.getType();

    if (type === 'Point' || type === 'MultiPoint') {
      const icon = resolveIcon(meta.properties, meta.style, meta.layer.style);
      return remember(
        `p|${icon.src}|${selected}`,
        () =>
          new Style({
            image: new Icon({
              src: icon.src,
              anchor: [0.5, 1],
              scale: selected ? 1.25 : 1,
              width: icon.custom ? 32 : undefined,
              height: icon.custom ? 32 : undefined,
            }),
            zIndex: selected ? 10 : 1,
          }),
      );
    }

    const weight = numberStyle(meta.style['weight'] ?? meta.layer.style['weight'], 1, 20, 3);
    const fillOpacity = numberStyle(
      meta.style['fillOpacity'] ?? meta.layer.style['fillOpacity'],
      0,
      1,
      0.25,
    );
    return remember(
      `g|${color}|${weight}|${fillOpacity}|${selected}`,
      () =>
        new Style({
          stroke: new Stroke({ color, width: selected ? weight + 2 : weight }),
          fill: new Fill({ color: hexToRgba(color, fillOpacity) }),
          image: new CircleStyle({ radius: 5, fill: new Fill({ color }) }),
        }),
    );
  };
}

/**
 * Style of a cluster layer: a single marker keeps its own style, a group is a circle with the number of markers
 * (the number, not only the colour, tells groups from markers).
 */
export function createClusterStyleFunction(single: StyleLike): StyleLike {
  const cache = new Map<number, Style>();
  return (feature, resolution) => {
    const members = (feature.get('features') ?? []) as FeatureLike[];
    if (members.length === 1) {
      const inner = members[0] as FeatureLike;
      return typeof single === 'function' ? single(inner, resolution) : undefined;
    }
    let style = cache.get(members.length);
    if (!style) {
      const radius = Math.min(
        26,
        13 + String(members.length).length * 3 + Math.log2(members.length),
      );
      style = new Style({
        image: new CircleStyle({
          radius,
          fill: new Fill({ color: '#1d4ed8' }),
          stroke: new Stroke({ color: '#ffffff', width: 3 }),
        }),
        text: new Text({
          text: String(members.length),
          fill: new Fill({ color: '#ffffff' }),
          font: '600 13px sans-serif',
        }),
        zIndex: 5,
      });
      cache.set(members.length, style);
    }
    return style;
  };
}

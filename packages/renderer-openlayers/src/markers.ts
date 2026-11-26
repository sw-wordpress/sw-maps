import { safeColor } from '@sw-maps/map-contracts';
import type { FeatureProperties, StyleMap } from '@sw-maps/map-contracts';

export const DEFAULT_COLOR = '#c0392b';

const GLYPHS: Record<string, string> = {
  pin: '<circle cx="12" cy="9" r="3" fill="#fff"/>',
  star: '<path d="M12 5l1.9 3.9 4.3.6-3.1 3 .7 4.3-3.8-2-3.8 2 .7-4.3-3.1-3 4.3-.6z" fill="#fff"/>',
  home: '<path d="M12 5.5l5 4.2V14h-3.4v-3h-3.2v3H7V9.7z" fill="#fff"/>',
  flag: '<path d="M9.5 5v9h1.3v-3.2l5-.8-1.3-2 1.3-2z" fill="#fff"/>',
  info: '<path d="M11 8h2v1.6h-2zm0 3h2v4.5h-2z" fill="#fff"/>',
  camera:
    '<path d="M8 8.3h1.6l.8-1.2h3.2l.8 1.2H16a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V9.3a1 1 0 0 1 1-1z" fill="#fff"/>',
  tree: '<path d="M12 5l3.6 5H13.4l2.4 3.4H8.2L10.6 10H8.4z" fill="#fff"/>',
  hiking:
    '<path d="M10 14.5l1.6-4.6-1-1.2 1.6-1.7 1.9 2.5-1.6 5zM13.4 7.4a1.1 1.1 0 1 0 0-.1z" fill="#fff"/>',
  restaurant:
    '<path d="M9 5.5v3.4a1.4 1.4 0 0 0 1 1.3v4.3h1V10.2a1.4 1.4 0 0 0 1-1.3V5.5h-.8v2.7h-.5V5.5h-.8v2.7h-.5V5.5zm6.2 0c-1 .8-1.4 2-1.4 3.6h1v5.4h1.2V5.5z" fill="#fff"/>',
  hotel:
    '<path d="M7.5 7v7h1.2v-1.5h6.6V14h1.2v-4.2a1.8 1.8 0 0 0-1.8-1.8h-4.4V7zm2 2.3a1 1 0 1 0 0 .1z" fill="#fff"/>',
};

/** Local marker icon as SVG data URI. Colour and glyph name come from validated values only. */
export function markerSvg(iconName: string, color: string): string {
  const glyph = GLYPHS[iconName] ?? GLYPHS['pin'];
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 24 31.5">` +
    `<path d="M12 .8C6.2.8 1.6 5.4 1.6 11.2 1.6 18.7 12 30.7 12 30.7S22.4 18.7 22.4 11.2C22.4 5.4 17.8.8 12 .8z" fill="${color}" stroke="#fff" stroke-width="1.2"/>` +
    `<g transform="translate(0 2)">${glyph}</g></svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

export interface IconSpec {
  src: string;
  /** true for local media (custom raster icon) */
  custom: boolean;
}

/** Resolves the marker icon of a feature: media icon URL when provided by the server, otherwise a built-in glyph. */
export function resolveIcon(
  properties: FeatureProperties,
  style: StyleMap,
  layerStyle: StyleMap,
  categoryStyle: StyleMap = {},
): IconSpec {
  if (
    properties.icon.startsWith('media:') &&
    typeof properties.icon_url === 'string' &&
    properties.icon_url !== ''
  ) {
    return { src: properties.icon_url, custom: true };
  }
  const color = safeColor(
    style['color'] ?? categoryStyle['color'] ?? layerStyle['color'],
    DEFAULT_COLOR,
  );
  // The default icon is no explicit choice: the category's icon applies (so categories differ by shape, not only colour).
  const categoryIcon = categoryStyle['icon'];
  const chosen =
    properties.icon === 'builtin:pin' && typeof categoryIcon === 'string' && categoryIcon !== ''
      ? categoryIcon
      : properties.icon;
  const name = chosen.startsWith('builtin:') ? chosen.slice(8) : 'pin';
  return { src: markerSvg(name, color), custom: false };
}

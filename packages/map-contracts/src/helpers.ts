import type { BoundsTuple, Position } from './index';

export const MAX_LONGITUDE = 180;
export const MAX_LATITUDE = 85.0511287798066;

export function isValidPosition(value: unknown): value is Position {
  if (!Array.isArray(value) || value.length < 2 || value.length > 3) return false;
  const [lon, lat] = value as unknown[];
  return (
    typeof lon === 'number' &&
    typeof lat === 'number' &&
    Number.isFinite(lon) &&
    Number.isFinite(lat) &&
    Math.abs(lon) <= MAX_LONGITUDE &&
    Math.abs(lat) <= MAX_LATITUDE
  );
}

/** Flattens any GeoJSON coordinate nesting into positions (invalid leaves are skipped). */
export function flattenPositions(coordinates: unknown): Position[] {
  if (!Array.isArray(coordinates)) return [];
  if (typeof coordinates[0] === 'number') {
    return isValidPosition(coordinates) ? [[coordinates[0], coordinates[1] as number]] : [];
  }
  return coordinates.flatMap((child) => flattenPositions(child));
}

export function boundsOf(positions: readonly Position[]): BoundsTuple | null {
  if (positions.length === 0) return null;
  let minLon = Infinity;
  let minLat = Infinity;
  let maxLon = -Infinity;
  let maxLat = -Infinity;
  for (const [lon, lat] of positions) {
    minLon = Math.min(minLon, lon);
    maxLon = Math.max(maxLon, lon);
    minLat = Math.min(minLat, lat);
    maxLat = Math.max(maxLat, lat);
  }
  return [minLon, minLat, maxLon, maxLat];
}

export type AttributionPart = { text: string; href?: string };

/**
 * Parses attribution text with `[label](https://url)` links into safe parts. Only http(s) targets become links;
 * everything else stays plain text, so the result can be rendered with DOM text nodes (never innerHTML).
 */
export function parseAttribution(input: string): AttributionPart[] {
  const parts: AttributionPart[] = [];
  const pattern = /\[([^\]]*)\]\(([^)\s]*)\)/g;
  let last = 0;
  for (const match of input.matchAll(pattern)) {
    const index = match.index ?? 0;
    if (index > last) parts.push({ text: input.slice(last, index) });
    const label = match[1] ?? '';
    const href = match[2] ?? '';
    parts.push(/^https?:\/\/[^\s"<>\\]+$/i.test(href) ? { text: label, href } : { text: label });
    last = index + match[0].length;
  }
  if (last < input.length) parts.push({ text: input.slice(last) });
  return parts;
}

const SAFE_LINK = /^(https?:\/\/[^\s"<>\\]+|mailto:[^\s]+|tel:[^\s]+)$/i;

/** Client side re-check of link targets (the server validates as well; this is defence in depth). */
export function safeLink(url: string | null | undefined): string | null {
  return typeof url === 'string' && SAFE_LINK.test(url) ? url : null;
}

/** Colour values accepted from style maps (hex only). */
export function safeColor(value: unknown, fallback: string): string {
  return typeof value === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(value) ? value : fallback;
}

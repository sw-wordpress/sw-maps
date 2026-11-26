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
const HTTP_LINK = /^https?:\/\/[^\s"<>\\]+$/i;
export function parseAttribution(input: string): AttributionPart[] {
  const parts: AttributionPart[] = [];
  let last = 0;
  let from = 0;
  // Linear scan for `[label](target)` (no regular expression, so no backtracking).
  for (;;) {
    const open = input.indexOf('[', from);
    if (open === -1) break;
    const close = input.indexOf(']', open + 1);
    if (close === -1 || input[close + 1] !== '(') {
      from = open + 1;
      continue;
    }
    const end = input.indexOf(')', close + 2);
    const href = end === -1 ? '' : input.slice(close + 2, end);
    if (end === -1 || /\s/.test(href) || input.slice(open + 1, close).includes(']')) {
      from = open + 1;
      continue;
    }
    if (open > last) parts.push({ text: input.slice(last, open) });
    const label = input.slice(open + 1, close);
    parts.push(HTTP_LINK.test(href) ? { text: label, href } : { text: label });
    last = end + 1;
    from = last;
  }
  if (last < input.length) parts.push({ text: input.slice(last) });
  return parts;
}

const CONTACT_LINK = /^(?:mailto|tel):\S+$/i;

/** Client side re-check of link targets (the server validates as well; this is defence in depth). */
export function safeLink(url: string | null | undefined): string | null {
  return typeof url === 'string' && (HTTP_LINK.test(url) || CONTACT_LINK.test(url)) ? url : null;
}

/** Colour values accepted from style maps (hex only). */
export function safeColor(value: unknown, fallback: string): string {
  return typeof value === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(value) ? value : fallback;
}

/** Mean earth radius in metres (identical to the PHP DistanceCalculator). */
export const EARTH_RADIUS_M = 6371008.8;

/** Great-circle (Haversine) distance in metres between two positions. */
export function distanceMeters(a: Position, b: Position): number {
  const rad = Math.PI / 180;
  const dLat = (b[1] - a[1]) * rad;
  const dLon = (b[0] - a[0]) * rad;
  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos(a[1] * rad) * Math.cos(b[1] * rad) * Math.sin(dLon / 2) ** 2;
  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Length in metres of a LineString/MultiLineString geometry (0 for every other geometry type). */
export function lineLengthMeters(geometry: { type: string; coordinates: unknown }): number {
  const path = (positions: Position[]): number =>
    positions.slice(1).reduce((sum, p, i) => sum + distanceMeters(positions[i] as Position, p), 0);
  if (geometry.type === 'LineString') return path(flattenPositions(geometry.coordinates));
  if (geometry.type === 'MultiLineString' && Array.isArray(geometry.coordinates)) {
    return geometry.coordinates.reduce(
      (sum: number, line) => sum + path(flattenPositions(line)),
      0,
    );
  }
  return 0;
}

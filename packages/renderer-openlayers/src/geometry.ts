import GeoJSON from 'ol/format/GeoJSON.js';
import type OlFeature from 'ol/Feature.js';
import type { Geometry as OlGeometry } from 'ol/geom.js';
import type { Geometry, Position } from '@sw-maps/map-contracts';
import { isValidPosition } from '@sw-maps/map-contracts';

const format = new GeoJSON({ dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' });

/** Converts a GeoJSON geometry (WGS84) into an OpenLayers geometry (Web Mercator); invalid input returns null. */
export function toOlGeometry(geometry: Geometry): OlGeometry | null {
  try {
    const result = format.readGeometry(geometry);
    return result ?? null;
  } catch {
    return null;
  }
}

/** Reads the geometry of an OpenLayers feature back as GeoJSON in WGS84, rounded to 7 decimals (~1 cm). */
export function fromOlFeature(feature: OlFeature): Geometry | null {
  const geometry = feature.getGeometry();
  if (!geometry) return null;
  const json = format.writeGeometryObject(geometry, { decimals: 7 }) as unknown as {
    type: Geometry['type'];
    coordinates: unknown;
  };
  return { type: json.type, coordinates: json.coordinates };
}

export function isPosition(value: unknown): value is Position {
  return isValidPosition(value);
}

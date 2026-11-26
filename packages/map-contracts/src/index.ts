/**
 * Typed data contracts shared by the admin app and the renderers. Coordinates are WGS84 and follow the GeoJSON
 * order [longitude, latitude]. Keep this package free of runtime dependencies.
 */

export type Position = [longitude: number, latitude: number];
/** [minLon, minLat, maxLon, maxLat] */
export type BoundsTuple = [number, number, number, number];

export type GeometryType =
  'Point' | 'MultiPoint' | 'LineString' | 'MultiLineString' | 'Polygon' | 'MultiPolygon';

export interface Geometry {
  type: GeometryType;
  coordinates: unknown;
}

export interface PointGeometry extends Geometry {
  type: 'Point';
  coordinates: Position;
}

export type StyleValue = string | number | boolean | null;
export type StyleMap = Record<string, StyleValue>;

export interface FeatureProperties {
  title: string;
  description: string;
  address: string;
  image_id: number | null;
  link: string | null;
  /** `builtin:<name>` or `media:<attachment id>` */
  icon: string;
  /** Structured additional fields (label/value pairs, plain text). */
  fields?: { label: string; value: string }[];
  /** Public output only: resolved media URLs (local files). */
  image_url?: string | null;
  icon_url?: string | null;
}

export interface CategoryData {
  id: number;
  slug: string;
  title: string;
  /** `color` (hex) and `icon` (`builtin:<name>`) are the defaults for the locations of the category. */
  style: StyleMap;
}

export interface FeatureData {
  id: number;
  layer_id: number;
  external_id: string | null;
  geometry: Geometry;
  properties: FeatureProperties;
  style: StyleMap;
  categories?: number[];
  updated_at: string;
}

export type PublishStatus = 'draft' | 'published';

export interface LayerData {
  id: number;
  map_id: number;
  title: string;
  type: 'features';
  status: PublishStatus;
  sort_order: number;
  visible: boolean;
  style: StyleMap;
  created_at: string;
  updated_at: string;
}

export interface ViewportData {
  center: Position;
  zoom: number;
  minZoom: number;
  maxZoom: number;
  bounds: BoundsTuple | null;
  autoBounds: boolean;
}

export interface MapSettingsData {
  height: number;
  aspectRatio: string | null;
  scrollZoom: boolean;
  touch: boolean;
  drag: boolean;
  zoomControls: boolean;
  fullscreen: boolean;
  scale: boolean;
  showList: boolean;
  clickToLoad: boolean;
  /** Viewer only: group nearby markers into clickable clusters. */
  cluster: boolean;
  /** Features are loaded for the visible area instead of being embedded (large maps). */
  ajax: boolean;
  providerId: number | null;
}

export interface MapData {
  id: number;
  slug: string;
  title: string;
  description: string;
  status: PublishStatus;
  viewport: ViewportData;
  settings: MapSettingsData;
  layer_count?: number;
  feature_count?: number;
  created_at: string;
  updated_at: string;
}

export interface ProviderData {
  id: number;
  type: 'xyz' | 'pmtiles';
  name: string;
  public_config: {
    urlTemplate: string;
    attribution: string;
    minZoom: number;
    maxZoom: number;
    tileSize: 256 | 512;
    publicKey: string;
  };
  secret_constant: string | null;
  secret_defined?: boolean;
  external_requests?: string[];
  enabled: boolean;
}

/** Provider as delivered to visitors: the key is already resolved, no secrets. */
export interface PublicProvider {
  type: 'xyz' | 'pmtiles';
  urlTemplate: string;
  attribution: string;
  minZoom: number;
  maxZoom: number;
  tileSize: number;
}

/** Translated UI strings of the frontend viewer (supplied by the server, the viewer has no i18n runtime). */
export interface ViewerStrings {
  zoomIn: string;
  zoomOut: string;
  fullscreen: string;
  exitFullscreen: string;
  loadMap: string;
  consentRequired: string;
  clickToLoad: string;
  error: string;
  attributionLabel: string;
  openLink: string;
  closePopup: string;
  search: string;
  filterByCategory: string;
  noResults: string;
}

export interface PublicFeature {
  id: number;
  geometry: Geometry;
  properties: FeatureProperties;
  style: StyleMap;
  categories?: number[];
  /** Display variant: the geometry was simplified for output (the stored geometry is unchanged). */
  simplified?: boolean;
}

export interface PublicCategory {
  id: number;
  title: string;
  style: StyleMap;
}

export interface PublicLayer {
  id: number;
  title: string;
  style: StyleMap;
  features: PublicFeature[];
}

/** The JSON embedded next to every rendered map. */
export interface MapRenderData {
  id: number;
  title: string;
  viewport: ViewportData;
  settings: MapSettingsData;
  provider: PublicProvider | null;
  layers: PublicLayer[];
  categories?: PublicCategory[];
  /** Public features endpoint when the map loads its features on demand (AJAX mode); null/absent = inline. */
  ajaxUrl?: string | null;
  /** WP Consent API category that must be granted before tiles are requested; null = no gating. */
  consentCategory: string | null;
  strings: ViewerStrings;
}

export interface RendererView {
  center: Position;
  zoom: number;
}

export type RendererEventMap = {
  /** A feature was selected (id) or the selection was cleared (null). */
  select: number | null;
  /** The visible view changed (after the interaction ended). */
  viewchange: RendererView;
  /** A non recoverable renderer error. */
  error: Error;
};

export type Unsubscribe = () => void;

export interface RendererOptions {
  data: MapRenderData;
}

/**
 * Minimal renderer contract (docs/adr/0002): mount, update, setView, getView, fitBounds, on, destroy.
 * It is extended only when a second renderer is implemented.
 */
export interface MapRenderer {
  mount(target: HTMLElement, options: RendererOptions): void;
  update(data: MapRenderData): void;
  setView(view: Partial<RendererView>): void;
  getView(): RendererView;
  fitBounds(bounds: BoundsTuple, options?: { padding?: number; maxZoom?: number }): void;
  on<K extends keyof RendererEventMap>(
    event: K,
    handler: (payload: RendererEventMap[K]) => void,
  ): Unsubscribe;
  destroy(): void;
}

/** Editor extension used by the admin app only (not part of the minimal contract). */
export type EditTool = 'select' | 'point' | 'line' | 'polygon';

export interface EditorEventMap {
  /** A geometry was drawn with a draw tool. */
  draw: { geometry: Geometry };
  /** An existing feature was moved or its vertices changed. */
  geometrychange: { id: number; geometry: Geometry };
}

export interface EditableRenderer extends MapRenderer {
  setTool(tool: EditTool): void;
  onEdit<K extends keyof EditorEventMap>(
    event: K,
    handler: (payload: EditorEventMap[K]) => void,
  ): Unsubscribe;
  selectFeature(id: number | null): void;
}

export interface ApiErrorBody {
  code: string;
  message: string;
  data?: { status: number; details?: { field: string; message: string }[] };
}

export * from './helpers';

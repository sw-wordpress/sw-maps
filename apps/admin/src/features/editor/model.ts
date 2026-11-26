import type {
  FeatureData,
  FeatureProperties,
  Geometry,
  LayerData,
  MapData,
  MapRenderData,
  MapSettingsData,
  ProviderData,
  PublicProvider,
  StyleMap,
  ViewerStrings,
  ViewportData,
} from '@sw-maps/map-contracts';

/** Local working copy of one map: everything the editor can change before an explicit save. */
export interface EditorDoc {
  map: MapData;
  layers: LayerData[];
  features: FeatureData[];
}

export const EMPTY_PROPERTIES: FeatureProperties = {
  title: '',
  description: '',
  address: '',
  image_id: null,
  link: null,
  icon: 'builtin:pin',
};

export function cloneDoc(doc: EditorDoc): EditorDoc {
  return structuredClone(doc);
}

/** Only the parts that are saved (server managed timestamps are ignored). */
function comparable(doc: EditorDoc): unknown {
  const { title, description, status, slug, viewport, settings } = doc.map;
  return [
    { title, description, status, slug, viewport, settings },
    doc.layers.map((l) => [l.id, l.title, l.status, l.sort_order, l.visible, l.style]),
    doc.features.map((f) => [f.id, f.layer_id, f.geometry, f.properties, f.style]),
  ];
}

export function docEquals(a: EditorDoc, b: EditorDoc): boolean {
  return JSON.stringify(comparable(a)) === JSON.stringify(comparable(b));
}

// ---- pure edit operations (each returns a new doc) ---------------------------------------------------------------

export function updateMap(
  doc: EditorDoc,
  patch: {
    title?: string;
    description?: string;
    slug?: string;
    status?: MapData['status'];
    viewport?: Partial<ViewportData>;
    settings?: Partial<MapSettingsData>;
  },
): EditorDoc {
  const next = cloneDoc(doc);
  const { viewport, settings, ...flat } = patch;
  Object.assign(next.map, flat);
  if (viewport) Object.assign(next.map.viewport, viewport);
  if (settings) Object.assign(next.map.settings, settings);
  return next;
}

export function addLayer(doc: EditorDoc, tempId: number, title: string): EditorDoc {
  const next = cloneDoc(doc);
  const order = next.layers.reduce((max, l) => Math.max(max, l.sort_order), -1) + 1;
  next.layers.push({
    id: tempId,
    map_id: doc.map.id,
    title,
    type: 'features',
    status: 'published',
    sort_order: order,
    visible: true,
    style: {},
    created_at: '',
    updated_at: '',
  });
  return next;
}

export function updateLayer(
  doc: EditorDoc,
  id: number,
  patch: Partial<Pick<LayerData, 'title' | 'status' | 'visible' | 'style' | 'sort_order'>>,
): EditorDoc {
  const next = cloneDoc(doc);
  const layer = next.layers.find((l) => l.id === id);
  if (layer) Object.assign(layer, patch);
  return next;
}

export function removeLayer(doc: EditorDoc, id: number): EditorDoc {
  const next = cloneDoc(doc);
  next.layers = next.layers.filter((l) => l.id !== id);
  next.features = next.features.filter((f) => f.layer_id !== id);
  return next;
}

export function addFeature(
  doc: EditorDoc,
  tempId: number,
  layerId: number,
  geometry: Geometry,
  properties: Partial<FeatureProperties> = {},
): EditorDoc {
  const next = cloneDoc(doc);
  next.features.push({
    id: tempId,
    layer_id: layerId,
    external_id: null,
    geometry,
    properties: { ...EMPTY_PROPERTIES, ...properties },
    style: {},
    updated_at: '',
  });
  return next;
}

export function setFeatureGeometry(doc: EditorDoc, id: number, geometry: Geometry): EditorDoc {
  const next = cloneDoc(doc);
  const feature = next.features.find((f) => f.id === id);
  if (feature) feature.geometry = geometry;
  return next;
}

export function updateFeature(
  doc: EditorDoc,
  id: number,
  patch: { properties?: Partial<FeatureProperties>; style?: StyleMap },
): EditorDoc {
  const next = cloneDoc(doc);
  const feature = next.features.find((f) => f.id === id);
  if (feature) {
    if (patch.properties) Object.assign(feature.properties, patch.properties);
    if (patch.style) feature.style = { ...patch.style };
  }
  return next;
}

export function removeFeature(doc: EditorDoc, id: number): EditorDoc {
  const next = cloneDoc(doc);
  next.features = next.features.filter((f) => f.id !== id);
  return next;
}

// ---- undo / redo -------------------------------------------------------------------------------------------------

/** Snapshot history for local changes (no locking, no revisions: the last save wins). */
export class History {
  private past: EditorDoc[] = [];
  private future: EditorDoc[] = [];

  constructor(private readonly limit = 100) {}

  get canUndo(): boolean {
    return this.past.length > 0;
  }

  get canRedo(): boolean {
    return this.future.length > 0;
  }

  /** Records the state BEFORE a change. */
  push(snapshot: EditorDoc): void {
    this.past.push(cloneDoc(snapshot));
    if (this.past.length > this.limit) this.past.shift();
    this.future = [];
  }

  undo(current: EditorDoc): EditorDoc | null {
    const previous = this.past.pop();
    if (!previous) return null;
    this.future.push(cloneDoc(current));
    return previous;
  }

  redo(current: EditorDoc): EditorDoc | null {
    const next = this.future.pop();
    if (!next) return null;
    this.past.push(cloneDoc(current));
    return next;
  }

  clear(): void {
    this.past = [];
    this.future = [];
  }
}

// ---- save plan -----------------------------------------------------------------------------------------------------

export type Op =
  | { kind: 'map-update'; patch: Record<string, unknown> }
  | { kind: 'layer-delete'; id: number }
  | { kind: 'layer-create'; tempId: number; body: Record<string, unknown> }
  | { kind: 'layer-update'; id: number; patch: Record<string, unknown> }
  | { kind: 'feature-delete'; id: number }
  | { kind: 'feature-create'; tempId: number; layerId: number; body: Record<string, unknown> }
  | { kind: 'feature-update'; id: number; patch: Record<string, unknown> };

const isNew = (id: number): boolean => id < 0;

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/** Computes the requests needed to turn `original` (server state) into `working`. */
export function diff(original: EditorDoc, working: EditorDoc): Op[] {
  const ops: Op[] = [];
  const mapPatch: Record<string, unknown> = {};
  for (const key of ['title', 'description', 'slug', 'status'] as const) {
    if (original.map[key] !== working.map[key]) mapPatch[key] = working.map[key];
  }
  if (!same(original.map.viewport, working.map.viewport))
    mapPatch['viewport'] = working.map.viewport;
  if (!same(original.map.settings, working.map.settings))
    mapPatch['settings'] = working.map.settings;
  if (Object.keys(mapPatch).length > 0) ops.push({ kind: 'map-update', patch: mapPatch });

  const layerIds = new Set(working.layers.map((l) => l.id));
  const removedLayers = new Set(
    original.layers.filter((l) => !layerIds.has(l.id)).map((l) => l.id),
  );
  for (const id of removedLayers) ops.push({ kind: 'layer-delete', id });
  for (const layer of working.layers) {
    const before = original.layers.find((l) => l.id === layer.id);
    const body = {
      title: layer.title,
      status: layer.status,
      sort_order: layer.sort_order,
      visible: layer.visible,
      style: layer.style,
    };
    if (isNew(layer.id)) ops.push({ kind: 'layer-create', tempId: layer.id, body });
    else if (
      before &&
      !same(
        [before.title, before.status, before.sort_order, before.visible, before.style],
        [layer.title, layer.status, layer.sort_order, layer.visible, layer.style],
      )
    ) {
      ops.push({ kind: 'layer-update', id: layer.id, patch: body });
    }
  }

  const featureIds = new Set(working.features.map((f) => f.id));
  for (const f of original.features) {
    if (!featureIds.has(f.id) && !removedLayers.has(f.layer_id))
      ops.push({ kind: 'feature-delete', id: f.id });
  }
  for (const f of working.features) {
    const body = { geometry: f.geometry, properties: f.properties, style: f.style };
    if (isNew(f.id)) {
      ops.push({
        kind: 'feature-create',
        tempId: f.id,
        layerId: f.layer_id,
        body: { ...body, external_id: f.external_id },
      });
      continue;
    }
    const before = original.features.find((o) => o.id === f.id);
    if (
      before &&
      !same([before.geometry, before.properties, before.style], [f.geometry, f.properties, f.style])
    ) {
      ops.push({ kind: 'feature-update', id: f.id, patch: body });
    }
  }
  return ops;
}

export interface SaveApi {
  maps: { update(id: number, body: Record<string, unknown>): Promise<unknown> };
  layers: {
    create(mapId: number, body: Record<string, unknown>): Promise<{ id: number }>;
    update(id: number, body: Record<string, unknown>): Promise<unknown>;
    remove(id: number): Promise<unknown>;
  };
  features: {
    create(layerId: number, body: Record<string, unknown>): Promise<{ id: number }>;
    update(id: number, body: Record<string, unknown>): Promise<unknown>;
    remove(id: number): Promise<unknown>;
  };
}

/** A failed save: `ids` holds what was already created on the server so a retry does not create duplicates. */
export class SaveError extends Error {
  constructor(
    readonly cause: unknown,
    readonly ids: Map<number, number>,
  ) {
    super('Save failed');
    this.name = 'SaveError';
  }
}

/** Replaces temporary ids by the ids the server assigned. */
export function remapIds(doc: EditorDoc, ids: Map<number, number>): EditorDoc {
  const next = cloneDoc(doc);
  for (const layer of next.layers) layer.id = ids.get(layer.id) ?? layer.id;
  for (const feature of next.features) {
    feature.id = ids.get(feature.id) ?? feature.id;
    feature.layer_id = ids.get(feature.layer_id) ?? feature.layer_id;
  }
  return next;
}

/** Executes the plan in dependency order and returns the mapping of temporary to real ids. */
export async function runOps(api: SaveApi, mapId: number, ops: Op[]): Promise<Map<number, number>> {
  const ids = new Map<number, number>();
  try {
    await execute(api, mapId, ops, ids);
  } catch (error) {
    throw new SaveError(error, ids);
  }
  return ids;
}

async function execute(
  api: SaveApi,
  mapId: number,
  ops: Op[],
  ids: Map<number, number>,
): Promise<void> {
  let publishAfterwards = false;
  const order: Op['kind'][] = [
    'map-update',
    'layer-delete',
    'layer-create',
    'layer-update',
    'feature-delete',
    'feature-create',
    'feature-update',
  ];
  for (const kind of order) {
    for (const op of ops.filter((o) => o.kind === kind)) {
      switch (op.kind) {
        case 'map-update': {
          // Publishing waits until every content change succeeded (unpublishing and other edits go first), so a failed
          // save can never expose content the user meant to remove.
          const { status, ...rest } = op.patch;
          const deferPublish = status === 'published';
          const early = deferPublish ? rest : op.patch;
          if (Object.keys(early).length > 0) await api.maps.update(mapId, early);
          if (deferPublish) publishAfterwards = true;
          break;
        }
        case 'layer-delete':
          await api.layers.remove(op.id);
          break;
        case 'layer-create':
          ids.set(op.tempId, (await api.layers.create(mapId, op.body)).id);
          break;
        case 'layer-update':
          await api.layers.update(ids.get(op.id) ?? op.id, op.patch);
          break;
        case 'feature-delete':
          await api.features.remove(op.id);
          break;
        case 'feature-create':
          ids.set(
            op.tempId,
            (await api.features.create(ids.get(op.layerId) ?? op.layerId, op.body)).id,
          );
          break;
        case 'feature-update':
          await api.features.update(op.id, op.patch);
          break;
      }
    }
  }
  if (publishAfterwards) await api.maps.update(mapId, { status: 'published' });
}

// ---- render data for the editor canvas -------------------------------------------------------------------------------

export function toPublicProvider(provider: ProviderData | null): PublicProvider | null {
  if (!provider) return null;
  const c = provider.public_config;
  return {
    type: 'xyz',
    urlTemplate: c.urlTemplate.replace('{key}', encodeURIComponent(c.publicKey)),
    attribution: c.attribution,
    minZoom: c.minZoom,
    maxZoom: c.maxZoom,
    tileSize: c.tileSize,
  };
}

/** Builds the renderer input from the working copy (the editor always allows full interaction). */
export function buildRenderData(
  doc: EditorDoc,
  provider: ProviderData | null,
  strings: ViewerStrings,
): MapRenderData {
  const layers = doc.layers
    .filter((l) => l.visible)
    .map((l) => ({
      id: l.id,
      title: l.title,
      style: l.style,
      features: doc.features
        .filter((f) => f.layer_id === l.id)
        .map((f) => ({ id: f.id, geometry: f.geometry, properties: f.properties, style: f.style })),
    }));
  return {
    id: doc.map.id,
    title: doc.map.title,
    viewport: { ...doc.map.viewport, autoBounds: false },
    settings: {
      ...doc.map.settings,
      scrollZoom: true,
      touch: true,
      drag: true,
      zoomControls: true,
      fullscreen: false,
      clickToLoad: false,
    },
    provider: toPublicProvider(provider),
    layers,
    consentCategory: null,
    strings,
  };
}

/** Next temporary (negative) id for locally created layers and features. */
export function nextTempId(doc: EditorDoc): number {
  const min = Math.min(0, ...doc.layers.map((l) => l.id), ...doc.features.map((f) => f.id));
  return min - 1;
}

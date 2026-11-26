import { describe, expect, it, vi } from 'vitest';
import type { EditorDoc, SaveApi } from '@/features/editor/model';
import {
  moveLayer,
  History,
  SaveError,
  addFeature,
  addLayer,
  buildRenderData,
  cloneDoc,
  diff,
  docEquals,
  nextTempId,
  remapIds,
  removeFeature,
  removeLayer,
  runOps,
  setFeatureGeometry,
  toPublicProvider,
  updateFeature,
  updateLayer,
  updateMap,
} from '@/features/editor/model';
import { makeFeature, makeLayer, makeMap, makeProvider } from './helpers';
import { viewerStrings } from '@/utils/viewerStrings';

const base = (): EditorDoc => ({
  map: makeMap(),
  layers: [makeLayer()],
  features: [makeFeature()],
});
const point = (lon: number, lat: number) => ({ type: 'Point' as const, coordinates: [lon, lat] });

describe('pure edit operations', () => {
  it('never mutate the input and track dirtiness by content', () => {
    const doc = base();
    const next = updateMap(doc, { title: 'New', viewport: { zoom: 5 }, settings: { height: 600 } });
    expect(doc.map.title).toBe('Test map');
    expect([next.map.title, next.map.viewport.zoom, next.map.settings.height]).toEqual([
      'New',
      5,
      600,
    ]);
    expect(docEquals(doc, cloneDoc(doc))).toBe(true);
    expect(docEquals(doc, next)).toBe(false);
    const stamped = cloneDoc(doc);
    stamped.map.updated_at = 'later';
    expect(docEquals(doc, stamped)).toBe(true);
  });

  it('adds, updates and removes layers and features', () => {
    let doc = addLayer(base(), -1, 'Second');
    expect(doc.layers.map((l) => [l.id, l.sort_order])).toEqual([
      [10, 0],
      [-1, 1],
    ]);
    doc = updateLayer(doc, -1, { title: 'Renamed', visible: false });
    doc = addFeature(doc, -2, -1, point(1, 2), { title: 'New' });
    doc = setFeatureGeometry(doc, -2, point(3, 4));
    doc = updateFeature(doc, -2, { properties: { address: 'A' }, style: { color: '#fff' } });
    const f = doc.features.find((x) => x.id === -2)!;
    expect([f.properties.title, f.properties.address, f.geometry.coordinates, f.style]).toEqual([
      'New',
      'A',
      [3, 4],
      { color: '#fff' },
    ]);
    expect(doc.layers[1]).toMatchObject({ title: 'Renamed', visible: false });
    doc = removeFeature(doc, -2);
    expect(doc.features.some((x) => x.id === -2)).toBe(false);
    doc = removeLayer(doc, 10);
    expect(doc.layers.map((l) => l.id)).toEqual([-1]);
    expect(doc.features).toEqual([]);
    expect(updateLayer(doc, 999, { title: 'x' })).toEqual(doc);
    expect(setFeatureGeometry(doc, 999, point(0, 0))).toEqual(doc);
    expect(updateFeature(doc, 999, { properties: { title: 'x' } })).toEqual(doc);
  });

  it('hands out unique negative temporary ids', () => {
    let doc = base();
    const a = nextTempId(doc);
    doc = addLayer(doc, a, 'L');
    const b = nextTempId(doc);
    expect([a, b]).toEqual([-1, -2]);
  });
});

describe('History', () => {
  it('supports undo and redo and clears the redo branch on new changes', () => {
    const h = new History();
    const a = base();
    const b = updateMap(a, { title: 'B' });
    const c = updateMap(b, { title: 'C' });
    h.push(a);
    h.push(b);
    expect([h.canUndo, h.canRedo]).toEqual([true, false]);
    expect(h.undo(c)?.map.title).toBe('B');
    expect(h.undo(b)?.map.title).toBe('Test map');
    expect(h.undo(a)).toBeNull();
    expect(h.redo(a)?.map.title).toBe('B');
    expect(h.canRedo).toBe(true);
    h.push(a);
    expect(h.canRedo).toBe(false);
    expect(h.redo(a)).toBeNull();
    h.clear();
    expect(h.canUndo).toBe(false);
  });

  it('limits the number of snapshots', () => {
    const h = new History(3);
    for (let i = 0; i < 10; i++) h.push(updateMap(base(), { title: `T${i}` }));
    let steps = 0;
    let cur = base();
    for (let r = h.undo(cur); r; r = h.undo(cur)) {
      cur = r;
      steps++;
    }
    expect(steps).toBe(3);
  });
});

describe('layer order', () => {
  const three = (): EditorDoc => {
    let doc = base();
    doc = addLayer(doc, -1, 'B');
    doc = addLayer(doc, -2, 'C');
    return doc;
  };

  it('swaps neighbours, keeps the array sorted and ignores moves past the ends', () => {
    const doc = three();
    const order = (d: EditorDoc) => d.layers.map((l) => l.id);
    const first = doc.layers[0]!.id;
    expect(order(moveLayer(doc, first, -1))).toEqual(order(doc));
    expect(moveLayer(doc, first, -1)).toBe(doc);
    const down = moveLayer(doc, first, 1);
    expect(order(down)).toEqual([doc.layers[1]!.id, first, doc.layers[2]!.id]);
    expect(down.layers.map((l) => l.sort_order)).toEqual([0, 1, 2]);
    expect(moveLayer(down, -2, 1)).toBe(down);
    expect(moveLayer(down, 999, 1)).toBe(down);
    expect(diff(doc, down).some((o) => o.kind === 'layer-update')).toBe(true);
  });
});

describe('diff and save plan', () => {
  it('detects category changes and sends them with the feature', () => {
    const original = base();
    const working = updateFeature(original, 100, { categories: [3, 4] });
    expect(docEquals(original, working)).toBe(false);
    const ops = diff(original, working);
    expect(ops).toHaveLength(1);
    expect(ops[0]).toMatchObject({
      kind: 'feature-update',
      id: 100,
      patch: { categories: [3, 4] },
    });
    const created = addFeature(original, -5, 10, { type: 'Point', coordinates: [1, 1] });
    expect(diff(original, created)[0]).toMatchObject({
      kind: 'feature-create',
      body: { categories: [] },
    });
  });

  it('is empty without changes', () => {
    expect(diff(base(), base())).toEqual([]);
  });

  it('describes every kind of change', () => {
    const original: EditorDoc = {
      map: makeMap(),
      layers: [makeLayer(), makeLayer({ id: 20, title: 'Gone' })],
      features: [makeFeature(), makeFeature({ id: 101 }), makeFeature({ id: 200, layer_id: 20 })],
    };
    let working = updateMap(original, {
      title: 'T',
      status: 'published',
      viewport: { zoom: 3 },
      settings: { height: 500 },
    });
    working = removeLayer(working, 20);
    working = addLayer(working, -1, 'New layer');
    working = updateLayer(working, 10, { title: 'Renamed' });
    working = removeFeature(working, 101);
    working = addFeature(working, -2, -1, point(1, 1));
    working = addFeature(working, -3, 10, point(2, 2));
    working = setFeatureGeometry(working, 100, point(9, 9));
    const ops = diff(original, working);
    expect(ops.map((o) => o.kind).sort()).toEqual([
      'feature-create',
      'feature-create',
      'feature-delete',
      'feature-update',
      'layer-create',
      'layer-delete',
      'layer-update',
      'map-update',
    ]);
    expect(ops[0]).toMatchObject({ patch: { title: 'T', status: 'published' } });
    expect(ops.find((o) => o.kind === 'feature-delete')).toMatchObject({ id: 101 });
    expect(ops.some((o) => o.kind === 'feature-delete' && o.id === 200)).toBe(false);
  });

  it('executes in dependency order and maps temporary ids', async () => {
    const calls: string[] = [];
    const api: SaveApi = {
      maps: { update: vi.fn(async (id) => void calls.push(`map ${id}`)) },
      layers: {
        create: vi.fn(async () => (calls.push('layer+'), { id: 50 })),
        update: vi.fn(async (id) => void calls.push(`layer~${id}`)),
        remove: vi.fn(async (id) => void calls.push(`layer-${id}`)),
      },
      features: {
        create: vi.fn(async (layerId) => (calls.push(`feature+ in ${layerId}`), { id: 60 })),
        update: vi.fn(async (id) => void calls.push(`feature~${id}`)),
        remove: vi.fn(async (id) => void calls.push(`feature-${id}`)),
      },
    };
    const original = base();
    let working = addLayer(original, -1, 'L2');
    working = addFeature(working, -2, -1, point(1, 1));
    working = updateMap(working, { title: 'X' });
    working = updateLayer(working, 10, { title: 'Y' });
    working = setFeatureGeometry(working, 100, point(5, 5));
    working = removeFeature(working, 100);
    const ids = await runOps(api, 1, diff(original, working));
    expect(calls).toEqual(['map 1', 'layer+', 'layer~10', 'feature-100', 'feature+ in 50']);
    expect(Object.fromEntries(ids)).toEqual({ '-1': 50, '-2': 60 });
    const remapped = remapIds(working, ids);
    expect(remapped.layers.map((l) => l.id)).toEqual([10, 50]);
    expect(remapped.features.map((f) => [f.id, f.layer_id])).toEqual([[60, 50]]);
  });

  it('publishes only after all content changes succeeded, but unpublishes first', async () => {
    const calls: string[] = [];
    const api: SaveApi = {
      maps: { update: vi.fn(async (_id, body) => void calls.push(`map ${JSON.stringify(body)}`)) },
      layers: { create: vi.fn(), update: vi.fn(), remove: vi.fn() },
      features: {
        create: vi.fn(),
        update: vi.fn(),
        remove: vi.fn(async (id) => void calls.push(`feature-${id}`)),
      },
    };
    const original = base();
    let working = updateMap(original, { status: 'published', title: 'T' });
    working = removeFeature(working, 100);
    await runOps(api, 1, diff(original, working));
    expect(calls).toEqual(['map {"title":"T"}', 'feature-100', 'map {"status":"published"}']);

    calls.length = 0;
    const live = updateMap(base(), { status: 'published' });
    await runOps(api, 1, diff(live, updateMap(removeFeature(live, 100), { status: 'draft' })));
    expect(calls).toEqual(['map {"status":"draft"}', 'feature-100']);

    calls.length = 0;
    await runOps(api, 1, diff(original, updateMap(original, { status: 'published' })));
    expect(calls).toEqual(['map {"status":"published"}']);
  });

  it('reports what was created when a request fails', async () => {
    const api: SaveApi = {
      maps: { update: vi.fn() },
      layers: { create: vi.fn(async () => ({ id: 70 })), update: vi.fn(), remove: vi.fn() },
      features: {
        create: vi.fn(async () => Promise.reject(new Error('boom'))),
        update: vi.fn(),
        remove: vi.fn(),
      },
    };
    const original = base();
    let working = addLayer(original, -1, 'L2');
    working = addFeature(working, -2, -1, point(1, 1));
    const error = (await runOps(api, 1, diff(original, working)).catch(
      (e: unknown) => e,
    )) as SaveError;
    expect(error).toBeInstanceOf(SaveError);
    expect(Object.fromEntries(error.ids)).toEqual({ '-1': 70 });
    expect((error.cause as Error).message).toBe('boom');
  });
});

describe('render data', () => {
  const strings = viewerStrings((k) => k);

  it('resolves the provider key and drops private fields', () => {
    const p = makeProvider({
      public_config: {
        ...makeProvider().public_config,
        urlTemplate: 'https://t.example.org/{z}/{x}/{y}.png?k={key}',
        publicKey: 'a b',
      },
      secret_constant: 'SECRET',
    });
    const pub = toPublicProvider(p)!;
    expect(pub.urlTemplate).toBe('https://t.example.org/{z}/{x}/{y}.png?k=a%20b');
    expect(JSON.stringify(pub)).not.toContain('SECRET');
    expect(toPublicProvider(null)).toBeNull();
  });

  it('builds editor render data with full interaction and visible layers only', () => {
    let doc = addLayer(base(), -1, 'Hidden');
    doc = updateLayer(doc, -1, { visible: false });
    doc.map.settings.scrollZoom = false;
    doc.map.settings.clickToLoad = true;
    const data = buildRenderData(doc, makeProvider(), strings);
    expect(data.layers.map((l) => l.id)).toEqual([10]);
    expect(data.settings).toMatchObject({
      scrollZoom: true,
      drag: true,
      fullscreen: false,
      clickToLoad: false,
      cluster: false,
      ajax: false,
    });
    expect(data.viewport.autoBounds).toBe(false);
    expect(data.consentCategory).toBeNull();
    expect(buildRenderData(doc, null, strings).provider).toBeNull();
  });
});

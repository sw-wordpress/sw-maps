import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { ApiError } from '@/api/client';
import { useEditorStore } from '@/stores/editor';
import { useMapsStore } from '@/stores/maps';
import { useProvidersStore } from '@/stores/providers';
import { useSettingsStore } from '@/stores/settings';
import { viewerStrings } from '@/utils/viewerStrings';
import { errorMessage } from '@/utils/errors';
import { configure, fakeApi, makeFeature, makeLayer, makeMap, setupPinia } from './helpers';

let api: ReturnType<typeof fakeApi>;

beforeEach(() => {
  configure();
  setupPinia();
  api = fakeApi();
});

describe('maps store', () => {
  it('loads with filters and ignores superseded responses', async () => {
    const store = useMapsStore();
    store.search = 'abc';
    store.status = 'published';
    await store.load();
    expect(api.maps.list).toHaveBeenCalledWith({
      search: 'abc',
      status: 'published',
      page: 1,
      per_page: 20,
    });
    expect(store.total).toBe(1);

    let release: (v: unknown) => void = () => undefined;
    api.maps.list.mockImplementationOnce(
      () => new Promise((resolve) => (release = resolve)) as never,
    );
    const slow = store.load();
    api.maps.list.mockResolvedValueOnce({
      items: [makeMap({ id: 9 })],
      total: 1,
      totalPages: 1,
    } as never);
    await store.load();
    release({ items: [makeMap({ id: 1 })], total: 5, totalPages: 1 });
    await slow;
    expect(store.items[0]?.id).toBe(9);
  });

  it('creates, duplicates, deletes and changes status, reloading afterwards', async () => {
    const store = useMapsStore();
    expect((await store.create('New')).id).toBe(5);
    await store.duplicate(1);
    await store.remove(1);
    await store.setStatus(1, 'published');
    expect(api.maps.update).toHaveBeenCalledWith(1, { status: 'published' });
    expect(api.maps.list).toHaveBeenCalledTimes(4);
    api.maps.list.mockRejectedValueOnce(new Error('x') as never);
    await expect(store.load()).rejects.toThrow();
    expect(store.loading).toBe(false);
  });
});

describe('providers and settings stores', () => {
  it('manages providers and remembers test results', async () => {
    const store = useProvidersStore();
    await store.load();
    expect(store.enabled).toHaveLength(1);
    await store.save(null, { name: 'x' });
    await store.save(1, { name: 'y' });
    expect(api.providers.create).toHaveBeenCalledTimes(1);
    expect(api.providers.update).toHaveBeenCalledWith(1, { name: 'y' });
    await store.setEnabled(1, false);
    await store.remove(1);
    expect((await store.test(1)).ok).toBe(true);
    expect(store.tests[1]?.ok).toBe(true);
  });

  it('loads and saves settings and status', async () => {
    const store = useSettingsStore();
    await store.load();
    expect(store.settings?.default_height).toBe(400);
    await store.save({ default_height: 500 });
    expect(store.settings?.default_height).toBe(500);
    await store.loadStatus();
    expect(store.status?.db_version).toBe(1);
  });
});

describe('editor store', () => {
  async function loaded() {
    const store = useEditorStore();
    await store.load(1);
    return store;
  }

  it('loads the map, layers, features and providers', async () => {
    const store = await loaded();
    expect(store.doc?.features).toHaveLength(1);
    expect(store.activeLayerId).toBe(10);
    expect(store.dirty).toBe(false);
    expect(store.provider?.name).toBe('OpenStreetMap');
    expect(store.renderData(viewerStrings((k) => k))?.layers).toHaveLength(1);
  });

  it('tolerates a failing provider list (no permission)', async () => {
    api.providers.list.mockRejectedValueOnce(new ApiError('rest_forbidden', 'no', 403) as never);
    const store = await loaded();
    expect(store.providers).toEqual([]);
    expect(store.provider).toBeNull();
  });

  it('tracks dirty state and supports undo/redo for local changes', async () => {
    const store = await loaded();
    store.patchMap({ title: 'Changed' });
    expect(store.dirty).toBe(true);
    expect([store.canUndo, store.canRedo]).toEqual([true, false]);
    store.undo();
    expect(store.doc?.map.title).toBe('Test map');
    expect(store.dirty).toBe(false);
    expect(store.canRedo).toBe(true);
    store.redo();
    expect(store.doc?.map.title).toBe('Changed');
    store.undo();
    store.undo();
  });

  it('adds markers (creating a layer on demand), moves them and edits properties', async () => {
    api.layers.forMap.mockResolvedValueOnce([] as never);
    const store = await loaded();
    expect(store.activeLayerId).toBeNull();
    const id = store.addGeometry({ type: 'Point', coordinates: [1, 2] }, { title: 'A' });
    expect(id).toBeLessThan(0);
    expect(store.doc?.layers).toHaveLength(1);
    expect(store.selectedFeature?.properties.title).toBe('A');
    store.moveFeature(id, { type: 'Point', coordinates: [3, 4] });
    store.patchFeature(id, { properties: { address: 'X' } });
    expect(store.selectedFeature?.geometry.coordinates).toEqual([3, 4]);
    expect(store.setPointCoordinates(id, 5, 6)).toBe(true);
    expect(store.setPointCoordinates(id, 500, 6)).toBe(false);
    expect(store.setPointCoordinates(999, 1, 1)).toBe(false);
    store.deleteFeature(id);
    expect(store.selectedFeatureId).toBeNull();
  });

  it('refuses coordinates for non point features and handles selection', async () => {
    api.features.page.mockResolvedValueOnce({
      items: [
        makeFeature({
          geometry: {
            type: 'LineString',
            coordinates: [
              [0, 0],
              [1, 1],
            ],
          },
        }),
      ],
      total: 1,
      totalPages: 1,
    } as never);
    const store = await loaded();
    expect(store.setPointCoordinates(100, 1, 1)).toBe(false);
    store.select(100);
    expect(store.selectedFeatureId).toBe(100);
    store.select(null);
    expect(store.selectedFeature).toBeNull();
  });

  it('layer management and snapshot consistency after undo', async () => {
    const store = await loaded();
    const layerId = store.createLayer('Second');
    expect(store.activeLayerId).toBe(layerId);
    store.patchLayer(layerId, { title: 'Renamed' });
    store.deleteLayer(layerId);
    expect(store.activeLayerId).toBe(10);
    store.undo();
    store.undo();
    store.undo();
    expect(store.doc?.layers.map((l) => l.id)).toEqual([10]);
    expect(store.activeLayerId).toBe(10);
  });

  it('only saves explicit changes and reloads the server state', async () => {
    const store = await loaded();
    expect(await store.save()).toBe(true);
    expect(api.maps.update).not.toHaveBeenCalled();
    store.patchMap({ title: 'Saved' });
    store.addGeometry({ type: 'Point', coordinates: [1, 1] });
    api.maps.get.mockResolvedValueOnce(makeMap({ title: 'Saved' }) as never);
    api.features.page.mockResolvedValueOnce({
      items: [makeFeature(), makeFeature({ id: 101 })],
      total: 2,
      totalPages: 1,
    } as never);
    expect(await store.save()).toBe(true);
    expect(api.maps.update).toHaveBeenCalledWith(1, { title: 'Saved' });
    expect(api.features.create).toHaveBeenCalledWith(
      10,
      expect.objectContaining({ geometry: { type: 'Point', coordinates: [1, 1] } }),
    );
    expect(store.dirty).toBe(false);
    expect(store.canUndo).toBe(false);
    expect(store.selectedFeatureId).toBe(101);
  });

  it('keeps the work and remembers created ids when saving fails half way', async () => {
    const store = await loaded();
    const layerId = store.createLayer('New layer');
    store.addGeometry({ type: 'Point', coordinates: [1, 1] });
    api.features.create.mockRejectedValueOnce(
      new ApiError('swmap_invalid_geometry', 'Bad', 400) as never,
    );
    api.layers.forMap.mockResolvedValue([
      makeLayer(),
      makeLayer({ id: 11, title: 'New layer' }),
    ] as never);
    expect(await store.save()).toBe(false);
    expect(store.saveError).toBeInstanceOf(ApiError);
    expect(store.dirty).toBe(true);
    expect(store.doc?.layers.some((l) => l.id === layerId)).toBe(false);
    expect(store.doc?.layers.some((l) => l.id === 11)).toBe(true);
    api.features.create.mockResolvedValueOnce(makeFeature({ id: 102 }) as never);
    await store.save();
    expect(api.layers.create).toHaveBeenCalledTimes(1);
  });

  it('records non save errors and ignores a save while one is running', async () => {
    const store = await loaded();
    store.patchMap({ title: 'X' });
    api.maps.update.mockRejectedValueOnce(new ApiError('x', 'Boom', 500) as never);
    expect(await store.save()).toBe(false);
    expect(errorMessage(store.saveError, 'fallback')).toBe('Boom');
    const idle = useEditorStore();
    idle.original = null;
    expect(await idle.save()).toBe(false);
    api.maps.update.mockImplementationOnce(() => new Promise((r) => setTimeout(r, 20)) as never);
    const first = store.save();
    expect(await store.save()).toBe(false);
    await first;
    await flushPromises();
  });

  it('throws when editing without a loaded map', () => {
    const store = useEditorStore();
    expect(() => store.patchMap({ title: 'x' })).toThrow('No map loaded');
    store.undo();
    store.redo();
    expect(store.renderData(viewerStrings((k) => k))).toBeNull();
  });

  it('errorMessage formats API errors with details and falls back otherwise', () => {
    expect(
      errorMessage(
        new ApiError('c', 'Invalid input.', 400, [{ field: 'title', message: 'Required.' }]),
        'f',
      ),
    ).toBe('Invalid input. (title: Required.)');
    expect(errorMessage(new Error('x'), 'fallback')).toBe('fallback');
    void vi;
  });
});

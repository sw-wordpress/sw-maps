import { defineStore } from 'pinia';
import { computed, ref, shallowRef } from 'vue';
import type {
  EditTool,
  FeatureProperties,
  Geometry,
  MapRenderData,
  ProviderData,
  StyleMap,
  ViewerStrings,
} from '@sw-maps/map-contracts';
import { isValidPosition } from '@sw-maps/map-contracts';
import { api } from '@/api';
import type { EditorDoc } from '@/features/editor/model';
import {
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
  updateFeature,
  updateLayer,
  updateMap,
} from '@/features/editor/model';

type MapPatch = Parameters<typeof updateMap>[1];

export const useEditorStore = defineStore('editor', () => {
  const original = shallowRef<EditorDoc | null>(null);
  const doc = shallowRef<EditorDoc | null>(null);
  const providers = ref<ProviderData[]>([]);
  const history = new History();
  const historyTick = ref(0);
  const selectedFeatureId = ref<number | null>(null);
  const activeLayerId = ref<number | null>(null);
  const tool = ref<EditTool>('select');
  const loading = ref(false);
  const saving = ref(false);
  const saveError = ref<unknown>(null);

  const dirty = computed(
    () => original.value !== null && doc.value !== null && !docEquals(original.value, doc.value),
  );
  const canUndo = computed(() => historyTick.value >= 0 && history.canUndo);
  const canRedo = computed(() => historyTick.value >= 0 && history.canRedo);
  const selectedFeature = computed(
    () => doc.value?.features.find((f) => f.id === selectedFeatureId.value) ?? null,
  );
  const activeLayer = computed(
    () => doc.value?.layers.find((l) => l.id === activeLayerId.value) ?? null,
  );
  const provider = computed(() => {
    const id = doc.value?.map.settings.providerId;
    return (
      providers.value.find((p) => p.id === id && p.enabled) ??
      providers.value.find((p) => p.enabled) ??
      null
    );
  });

  function renderData(strings: ViewerStrings): MapRenderData | null {
    return doc.value ? buildRenderData(doc.value, provider.value, strings) : null;
  }

  async function fetchDoc(mapId: number): Promise<EditorDoc> {
    const [map, layers] = await Promise.all([api().maps.get(mapId), api().layers.forMap(mapId)]);
    const pages = await Promise.all(layers.map((layer) => api().features.page(layer.id, 1, 500)));
    return { map, layers, features: pages.flatMap((p) => p.items) };
  }

  async function load(mapId: number): Promise<void> {
    loading.value = true;
    try {
      const [fetched, provs] = await Promise.all([
        fetchDoc(mapId),
        api()
          .providers.list()
          .catch(() => [] as ProviderData[]),
      ]);
      providers.value = provs;
      original.value = fetched;
      doc.value = cloneDoc(fetched);
      history.clear();
      historyTick.value++;
      selectedFeatureId.value = null;
      activeLayerId.value = fetched.layers[0]?.id ?? null;
      tool.value = 'select';
    } finally {
      loading.value = false;
    }
  }

  function commit(next: EditorDoc): void {
    if (!doc.value) return;
    history.push(doc.value);
    doc.value = next;
    historyTick.value++;
  }

  function current(): EditorDoc {
    if (!doc.value) throw new Error('No map loaded');
    return doc.value;
  }

  function patchMap(patch: MapPatch): void {
    commit(updateMap(current(), patch));
  }

  function createLayer(title: string): number {
    const id = nextTempId(current());
    commit(addLayer(current(), id, title));
    activeLayerId.value = id;
    return id;
  }

  function patchLayer(id: number, patch: Parameters<typeof updateLayer>[2]): void {
    commit(updateLayer(current(), id, patch));
  }

  function deleteLayer(id: number): void {
    commit(removeLayer(current(), id));
    if (selectedFeature.value === null) selectedFeatureId.value = null;
    if (activeLayerId.value === id) activeLayerId.value = doc.value?.layers[0]?.id ?? null;
  }

  /** Adds a feature to the active layer (a layer is created on demand). Returns the new feature id. */
  function addGeometry(geometry: Geometry, properties: Partial<FeatureProperties> = {}): number {
    if (activeLayerId.value === null) createLayer('Layer 1');
    const id = nextTempId(current());
    commit(addFeature(current(), id, activeLayerId.value as number, geometry, properties));
    selectedFeatureId.value = id;
    return id;
  }

  function moveFeature(id: number, geometry: Geometry): void {
    commit(setFeatureGeometry(current(), id, geometry));
  }

  /** Sets the coordinates of the selected point feature; invalid input is rejected (returns false). */
  function setPointCoordinates(id: number, lon: number, lat: number): boolean {
    const feature = current().features.find((f) => f.id === id);
    if (!feature || feature.geometry.type !== 'Point' || !isValidPosition([lon, lat])) return false;
    moveFeature(id, { type: 'Point', coordinates: [lon, lat] });
    return true;
  }

  function patchFeature(
    id: number,
    patch: { properties?: Partial<FeatureProperties>; style?: StyleMap },
  ): void {
    commit(updateFeature(current(), id, patch));
  }

  function deleteFeature(id: number): void {
    commit(removeFeature(current(), id));
    if (selectedFeatureId.value === id) selectedFeatureId.value = null;
  }

  function select(id: number | null): void {
    selectedFeatureId.value = id;
    const feature = doc.value?.features.find((f) => f.id === id);
    if (feature) activeLayerId.value = feature.layer_id;
  }

  function applySnapshot(next: EditorDoc | null): void {
    if (!next) return;
    doc.value = next;
    historyTick.value++;
    if (
      selectedFeatureId.value !== null &&
      !next.features.some((f) => f.id === selectedFeatureId.value)
    )
      selectedFeatureId.value = null;
    if (activeLayerId.value !== null && !next.layers.some((l) => l.id === activeLayerId.value))
      activeLayerId.value = next.layers[0]?.id ?? null;
  }

  function undo(): void {
    if (doc.value) applySnapshot(history.undo(doc.value));
  }

  function redo(): void {
    if (doc.value) applySnapshot(history.redo(doc.value));
  }

  /** Explicit save: sends only the differences; the last save wins (no locking). */
  async function save(): Promise<boolean> {
    if (!original.value || !doc.value || saving.value) return false;
    saving.value = true;
    saveError.value = null;
    const mapId = doc.value.map.id;
    const selectedBefore = selectedFeatureId.value;
    const activeBefore = activeLayerId.value;
    try {
      const ids = await runOps(api(), mapId, diff(original.value, doc.value));
      selectedFeatureId.value =
        selectedBefore === null ? null : (ids.get(selectedBefore) ?? selectedBefore);
      activeLayerId.value = activeBefore === null ? null : (ids.get(activeBefore) ?? activeBefore);
      const fresh = await fetchDoc(mapId);
      original.value = fresh;
      doc.value = cloneDoc(fresh);
      history.clear();
      historyTick.value++;
      return true;
    } catch (error) {
      if (error instanceof SaveError) {
        // Keep the work; what was already created must not be created twice on retry.
        doc.value = remapIds(doc.value, error.ids);
        original.value = await fetchDoc(mapId).catch(() => original.value);
        saveError.value = error.cause;
      } else {
        saveError.value = error;
      }
      return false;
    } finally {
      saving.value = false;
    }
  }

  return {
    original,
    doc,
    providers,
    selectedFeatureId,
    activeLayerId,
    tool,
    loading,
    saving,
    saveError,
    dirty,
    canUndo,
    canRedo,
    selectedFeature,
    activeLayer,
    provider,
    renderData,
    load,
    patchMap,
    createLayer,
    patchLayer,
    deleteLayer,
    addGeometry,
    moveFeature,
    setPointCoordinates,
    patchFeature,
    deleteFeature,
    select,
    undo,
    redo,
    save,
  };
});

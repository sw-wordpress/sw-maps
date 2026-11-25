import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { FakeRenderer } from './rendererMock';
import EditorView from '@/features/editor/EditorView.vue';
import EditorCanvas from '@/features/editor/EditorCanvas.vue';
import { ApiError } from '@/api/client';
import { useEditorStore } from '@/stores/editor';
import { viewerStrings } from '@/utils/viewerStrings';
import { buildRenderData } from '@/features/editor/model';
import { notifier } from './notifier';
import {
  configure,
  fakeApi,
  makeFeature,
  makeLayer,
  makeMap,
  makeProvider,
  mountView,
} from './helpers';
import { mount } from '@vue/test-utils';

vi.mock('@sw-maps/renderer-openlayers', async () => {
  const { FakeRenderer: Fake } = await import('./rendererMock');
  return { EditableOpenLayersRenderer: Fake };
});
vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
const router = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/maps', component: { template: '<div />' } },
    ],
  });

async function mountEditor() {
  const r = router();
  await r.push('/');
  return mountView(EditorView, { id: '1' }, { plugins: [r] });
}

beforeEach(() => {
  document.body.innerHTML = '';
  FakeRenderer.reset();
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
});

describe('EditorView', () => {
  it('loads the map and mounts the renderer with the working copy', async () => {
    const w = await mountEditor();
    expect(w.text()).toContain('Test map');
    expect(w.findAll('[data-test=feature-item]')).toHaveLength(1);
    const renderer = FakeRenderer.instances[0]!;
    expect(renderer.mount).toHaveBeenCalledTimes(1);
    expect(renderer.setTool).toHaveBeenCalledWith('select');
  });

  it('adds a marker when the renderer reports a drawn geometry and then switches back to select', async () => {
    const w = await mountEditor();
    const store = useEditorStore();
    store.tool = 'point';
    FakeRenderer.instances[0]!.fire('edit:draw', {
      geometry: { type: 'Point', coordinates: [9, 50] },
    });
    await flushPromises();
    expect(store.doc?.features).toHaveLength(2);
    expect(store.tool).toBe('select');
    expect(w.find('[data-test=feature-form]').exists()).toBe(true);
    expect(w.find('[data-test=dirty]').exists()).toBe(true);
  });

  it('applies moved geometries from the renderer, selection from the map, and saves explicitly', async () => {
    const w = await mountEditor();
    const store = useEditorStore();
    const renderer = FakeRenderer.instances[0]!;
    renderer.fire('edit:geometrychange', {
      id: 100,
      geometry: { type: 'Point', coordinates: [9.5, 50.5] },
    });
    renderer.fire('on:select', 100);
    await flushPromises();
    expect(store.selectedFeatureId).toBe(100);
    expect(w.find('[data-test=feature-form]').exists()).toBe(true);
    expect(renderer.selectFeature).toHaveBeenCalledWith(100);
    expect(api.features.update).not.toHaveBeenCalled();
    await w.find('[data-test=save]').trigger('click');
    await flushPromises();
    expect(api.features.update).toHaveBeenCalledWith(
      100,
      expect.objectContaining({ geometry: { type: 'Point', coordinates: [9.5, 50.5] } }),
    );
    expect(notifier.success).toHaveBeenCalledWith('Map saved.');
  });

  it('shows save errors', async () => {
    const w = await mountEditor();
    useEditorStore().patchMap({ title: 'X' });
    api.maps.update.mockRejectedValue(
      new ApiError('swmap_conflict', 'This value is already in use.', 409) as never,
    );
    await flushPromises();
    await w.find('[data-test=save]').trigger('click');
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('This value is already in use.');
  });

  it('undo and redo work through the toolbar', async () => {
    const w = await mountEditor();
    const store = useEditorStore();
    store.patchMap({ title: 'Changed' });
    await flushPromises();
    await w.find('[data-test=undo]').trigger('click');
    expect(store.doc?.map.title).toBe('Test map');
    await w.find('[data-test=redo]').trigger('click');
    expect(store.doc?.map.title).toBe('Changed');
  });

  it('takes over the current view and fits all features', async () => {
    const w = await mountEditor();
    const renderer = FakeRenderer.instances[0]!;
    renderer.getView.mockReturnValue({ center: [9.123, 50.456], zoom: 12.3456 });
    await w.find('[data-test=use-view]').trigger('click');
    expect(useEditorStore().doc?.map.viewport).toMatchObject({
      center: [9.123, 50.456],
      zoom: 12.35,
    });
    await w.find('[data-test=fit]').trigger('click');
    expect(renderer.fitBounds).toHaveBeenCalledWith([8.4, 49, 8.4, 49]);
  });

  it('manages layers and features through the side panel', async () => {
    const w = await mountEditor();
    const store = useEditorStore();
    await w.find('[data-test=add-layer]').trigger('click');
    expect(store.doc?.layers).toHaveLength(2);
    expect(store.doc?.layers[1]?.title).toBe('Layer 2');
    await w.findAll('[data-test=toggle-layer]')[0]?.trigger('click');
    expect(store.doc?.layers[0]?.visible).toBe(false);
    await w.findAll('[data-test=feature-item]')[0]?.trigger('click');
    expect(store.selectedFeatureId).toBe(100);
    await w.find('[data-test=delete]').trigger('click');
    expect(store.doc?.features).toHaveLength(0);
    await w.find('[data-test=tab-map]').trigger('click');
    expect(w.find('[data-test=map-form]').exists()).toBe(true);
  });

  it('shows a hint instead of the feature form without a selection', async () => {
    const w = await mountEditor();
    await w.find('[data-test=tab-feature]').trigger('click');
    expect(w.find('[data-test=select-hint]').exists()).toBe(true);
  });

  it('shows a reload option when loading fails', async () => {
    api.maps.get.mockRejectedValueOnce(new ApiError('swmap_not_found', 'Not found.', 404) as never);
    const w = await mountEditor();
    expect(w.find('[role=alert]').text()).toContain('The map could not be loaded.');
    expect(notifier.error).toHaveBeenCalledWith('Not found.');
    await w.find('[role=alert] button').trigger('click');
    await flushPromises();
    expect(w.text()).toContain('Test map');
  });

  it('warns about unsaved changes when the page is closed', async () => {
    const w = await mountEditor();
    const clean = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(clean);
    expect(clean.defaultPrevented).toBe(false);
    useEditorStore().patchMap({ title: 'Dirty' });
    const dirty = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(dirty);
    expect(dirty.defaultPrevented).toBe(true);
    w.unmount();
    const after = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(after);
    expect(after.defaultPrevented).toBe(false);
  });

  it('reloads when the route id changes', async () => {
    const w = await mountEditor();
    await w.setProps({ id: '2' });
    await flushPromises();
    expect(api.maps.get).toHaveBeenLastCalledWith(2);
  });
});

describe('EditorCanvas', () => {
  const strings = viewerStrings((k) => k);
  const data = () =>
    buildRenderData(
      { map: makeMap(), layers: [makeLayer()], features: [makeFeature()] },
      makeProvider(),
      strings,
    );

  it('forwards tool, selection and data changes to the renderer', async () => {
    const w = mount(EditorCanvas, {
      props: { data: data(), tool: 'select', selectedId: null, mobile: false },
      attachTo: document.body,
    });
    const r = FakeRenderer.instances[0]!;
    await w.setProps({ tool: 'line' });
    await w.setProps({ selectedId: 100 });
    const next = data();
    next.viewport = { ...next.viewport, center: [9, 50], zoom: 12 };
    await w.setProps({ data: next });
    expect(r.setTool).toHaveBeenLastCalledWith('line');
    expect(r.selectFeature).toHaveBeenCalledWith(100);
    expect(r.update).toHaveBeenCalledWith(next);
    expect(r.setView).toHaveBeenCalledWith({ center: [9, 50], zoom: 12 });
    w.unmount();
    expect(r.destroy).toHaveBeenCalled();
  });

  it('does not reset the view while the user pans (view equals the stored start view)', async () => {
    const w = mount(EditorCanvas, {
      props: { data: data(), tool: 'select', selectedId: null, mobile: true },
    });
    const r = FakeRenderer.instances[0]!;
    expect(w.classes()).toContain('swmap-editor__canvas--mobile');
    await w.setProps({ data: data() });
    expect(r.setView).not.toHaveBeenCalled();
    expect(w.vm.getView()).toEqual({ center: [8.4, 49], zoom: 10 });
    w.vm.fitBounds([1, 2, 3, 4]);
    expect(r.fitBounds).toHaveBeenCalledWith([1, 2, 3, 4]);
  });

  it('mounts when data arrives late and degrades when the renderer fails', async () => {
    const late = mount(EditorCanvas, {
      props: { data: null, tool: 'select', selectedId: null, mobile: false },
    });
    expect(FakeRenderer.instances).toHaveLength(0);
    expect(late.vm.getView()).toBeNull();
    await late.setProps({ data: data() });
    expect(FakeRenderer.instances).toHaveLength(1);
    FakeRenderer.failOnMount = true;
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const broken = mount(EditorCanvas, {
      props: { data: data(), tool: 'select', selectedId: null, mobile: false },
    });
    await flushPromises();
    expect(broken.find('[role=alert]').text()).toBe('The map could not be displayed.');
    expect(spy).toHaveBeenCalled();
    await broken.setProps({ data: null });
  });
});

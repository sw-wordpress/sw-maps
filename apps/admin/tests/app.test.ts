import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { Notify } from 'quasar';
import App from '@/App.vue';
import { createAppRouter, retryImport, routes, setUnsavedMessage } from '@/router';
import { useEditorStore } from '@/stores/editor';
import { useNotify } from '@/composables/useNotify';
import { configure, fakeApi, makeMap } from './helpers';

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  fakeApi();
});

async function mountApp(capabilities?: string[]) {
  if (capabilities) configure(capabilities);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div data-test="home" />' } },
      { path: '/maps', component: { template: '<div />' } },
      { path: '/providers', component: { template: '<div />' } },
      { path: '/settings', component: { template: '<div />' } },
      { path: '/status', component: { template: '<div />' } },
    ],
  });
  await router.push('/');
  const w = mount(App, { global: { plugins: [router, createPinia()] }, attachTo: document.body });
  await flushPromises();
  return w;
}

describe('App shell', () => {
  it('shows all sections to system managers', async () => {
    const w = await mountApp();
    const labels = w.findAll('a.q-tab').map((t) => t.text());
    expect(labels).toHaveLength(5);
    for (const [i, label] of [
      'Dashboard',
      'Maps',
      'Providers',
      'Settings',
      'System status',
    ].entries())
      expect(labels[i]).toContain(label);
  });

  it('hides provider, settings and status for editors', async () => {
    const w = await mountApp(['swmap_edit_maps']);
    expect(w.findAll('a.q-tab')).toHaveLength(2);
  });
});

describe('router', () => {
  it('declares all views (lazy loaded) and a catch-all redirect', async () => {
    expect(routes.map((r) => r.name).filter(Boolean)).toEqual([
      'dashboard',
      'maps',
      'editor',
      'providers',
      'settings',
      'status',
    ]);
    for (const route of routes)
      if (typeof route.component === 'function')
        await expect(route.component()).resolves.toBeTruthy();
  });

  it('asks before leaving the editor with unsaved changes', async () => {
    setActivePinia(createPinia());
    const confirmLeave = vi.fn(() => false);
    const router = createAppRouter(confirmLeave);
    setUnsavedMessage('Leave?');
    const editor = useEditorStore();
    editor.original = { map: makeMap(), layers: [], features: [] };
    editor.doc = { map: makeMap({ title: 'Changed' }), layers: [], features: [] };
    await router.push('/maps/1');
    await router.push('/maps');
    expect(confirmLeave).toHaveBeenCalledWith('Leave?');
    expect(router.currentRoute.value.name).toBe('editor');
    confirmLeave.mockReturnValue(true);
    await router.push('/maps');
    expect(router.currentRoute.value.name).toBe('maps');
  });

  it('does not ask when there is nothing to lose', async () => {
    setActivePinia(createPinia());
    const confirmLeave = vi.fn(() => false);
    const router = createAppRouter(confirmLeave);
    await router.push('/maps/1');
    await router.push('/');
    expect(confirmLeave).not.toHaveBeenCalled();
    expect(router.currentRoute.value.name).toBe('dashboard');
    await router.push('/does/not/exist');
    expect(router.currentRoute.value.path).toBe('/');
  });
});

describe('retryImport', () => {
  it('retries transient failures and gives up after the last attempt', async () => {
    const flaky = vi
      .fn()
      .mockRejectedValueOnce(new Error('net'))
      .mockRejectedValueOnce(new Error('net'))
      .mockResolvedValue('view');
    await expect(retryImport(flaky, 3, 1)()).resolves.toBe('view');
    expect(flaky).toHaveBeenCalledTimes(3);
    const broken = vi.fn().mockRejectedValue(new Error('gone'));
    await expect(retryImport(broken, 2, 1)()).rejects.toThrow('gone');
    expect(broken).toHaveBeenCalledTimes(2);
  });
});

describe('useNotify', () => {
  it('maps to Quasar notifications', () => {
    // Notify.create only exists once the Quasar plugin is installed with it; the composable is a thin mapping.
    const create = vi.fn(() => () => undefined);
    (Notify as unknown as { create: typeof create }).create = create;
    useNotify().success('ok');
    useNotify().error('bad');
    expect(create).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({ type: 'positive', message: 'ok' }),
    );
    expect(create).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({ type: 'negative', message: 'bad' }),
    );
  });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import MapsView from '@/features/maps/MapsView.vue';
import SettingsView from '@/features/settings/SettingsView.vue';
import { ApiError } from '@/api/client';
import { downloadFile } from '@/utils/download';
import { notifier } from './notifier';
import { configure, fakeApi, makeMap, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
const created: Array<{ href: string; download: string }> = [];

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
  created.length = 0;
  vi.stubGlobal(
    'URL',
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:file'), revokeObjectURL: vi.fn() }),
  );
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
    this: HTMLAnchorElement,
  ) {
    created.push({ href: this.href, download: this.download });
  });
  api.maps.list.mockResolvedValue({
    items: [makeMap({ id: 1, title: 'Alpha' })],
    total: 1,
    totalPages: 1,
  } as never);
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountMaps() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/maps/:id', name: 'editor', component: { template: '<div />' } },
    ],
  });
  await router.push('/');
  return mountView(MapsView, {}, { plugins: [router] });
}
const el = (s: string) => document.body.querySelector<HTMLElement>(s);

describe('downloadFile', () => {
  it('offers the content as a named file and releases the object URL', () => {
    vi.useFakeTimers();
    downloadFile({ filename: 'a.json', mime_type: 'application/json', content: '{}' });
    expect(created).toEqual([{ href: 'blob:file', download: 'a.json' }]);
    expect(document.querySelector('a[download]')).toBeNull();
    vi.advanceTimersByTime(1100);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:file');
    vi.useRealTimers();
  });
});

describe('maps export and package import', () => {
  it('exports a map in each format', async () => {
    await mountMaps();
    for (const [test, format] of [
      ['export-json', 'json'],
      ['export-geojson', 'geojson'],
      ['export-csv', 'csv'],
    ] as const) {
      el('[data-test=export]')!.click();
      await vi.waitFor(() => expect(el(`[data-test=${test}]`)).not.toBeNull());
      el(`[data-test=${test}]`)!.click();
      await flushPromises();
      expect(api.exports.map).toHaveBeenLastCalledWith(1, format);
      await vi.waitFor(() => expect(el(`[data-test=${test}]`)).toBeNull());
    }
    expect(created.map((c) => c.download)).toEqual(['map.json', 'map.geojson', 'map.csv']);
  });

  it('reports a failed export', async () => {
    api.exports.map.mockRejectedValueOnce(new Error('x') as never);
    await mountMaps();
    el('[data-test=export]')!.click();
    await flushPromises();
    el('[data-test=export-json]')!.click();
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('The export failed.');
  });

  it('hides export and import without the capability', async () => {
    configure(['swmap_edit_maps']);
    await mountMaps();
    expect(el('[data-test=export]')).toBeNull();
    expect(el('[data-test=import-package]')).toBeNull();
  });

  it('imports a package file and reloads the list', async () => {
    await mountMaps();
    const input = el('input[data-test=package-file]') as HTMLInputElement;
    const file = new File(['{"format":"sw-maps-package"}'], 'p.json');
    Object.defineProperty(file, 'text', { value: async () => '{"format":"sw-maps-package"}' });
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    const before = api.maps.list.mock.calls.length;
    input.dispatchEvent(new Event('change'));
    await flushPromises();
    expect(api.imports.package).toHaveBeenCalledWith('{"format":"sw-maps-package"}');
    expect(notifier.success).toHaveBeenCalledWith('1 map(s) imported as drafts.');
    expect(api.maps.list.mock.calls.length).toBeGreaterThan(before);
    // Nothing selected: nothing happens.
    Object.defineProperty(input, 'files', { value: [], configurable: true });
    input.dispatchEvent(new Event('change'));
    await flushPromises();
    expect(api.imports.package).toHaveBeenCalledTimes(1);
  });

  it('translates rejection reasons of a package import', async () => {
    const rejected = new ApiError('swmap_import_rejected', 'x', 422);
    rejected.reason = 'malformed';
    api.imports.package.mockRejectedValueOnce(rejected as never);
    await mountMaps();
    const input = el('input[data-test=package-file]') as HTMLInputElement;
    const file = new File(['x'], 'p.json');
    Object.defineProperty(file, 'text', { value: async () => 'x' });
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change'));
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith(
      'The file is not valid or not in the selected format.',
    );
    api.imports.package.mockRejectedValueOnce(new Error('boom') as never);
    input.dispatchEvent(new Event('change'));
    await flushPromises();
    expect(notifier.error).toHaveBeenLastCalledWith('The file could not be imported.');
  });
});

describe('settings export', () => {
  it('exports all maps and reports failures', async () => {
    const w = await mountView(SettingsView);
    await w.find('[data-test=export-all]').trigger('click');
    await flushPromises();
    expect(api.exports.all).toHaveBeenCalled();
    expect(created.map((c) => c.download)).toEqual(['all.json']);
    api.exports.all.mockRejectedValueOnce(new Error('x') as never);
    await w.find('[data-test=export-all]').trigger('click');
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('The export failed.');
  });
});

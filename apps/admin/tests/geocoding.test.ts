import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import GeocodeDialog from '@/features/editor/GeocodeDialog.vue';
import FeatureForm from '@/features/editor/FeatureForm.vue';
import SettingsView from '@/features/settings/SettingsView.vue';
import { ApiError } from '@/api/client';
import { configure, fakeApi, makeFeature, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
const el = (s: string) => document.body.querySelector<HTMLElement>(s);

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
});

async function open(props: Record<string, unknown>) {
  const w = await mountView(GeocodeDialog, { modelValue: false, mode: 'search', ...props });
  await w.setProps({ modelValue: true });
  await flushPromises();
  return w;
}

describe('GeocodeDialog', () => {
  it('does nothing until it is opened, then searches once and offers the results', async () => {
    const w = await mountView(GeocodeDialog, {
      modelValue: false,
      mode: 'search',
      query: 'Karlsruhe',
    });
    expect(api.geocoding.search).not.toHaveBeenCalled();
    await w.setProps({ modelValue: true });
    await flushPromises();
    expect(api.geocoding.search).toHaveBeenCalledTimes(1);
    expect(api.geocoding.search).toHaveBeenCalledWith('Karlsruhe');
    expect(el('[data-test=geocode-hits]')?.textContent).toContain('Karlsruhe Schloss');
    expect(el('[data-test=geocode-dialog]')?.textContent).toContain(
      'sent to the geocoding service',
    );
  });

  it('emits the chosen hit only after the user confirms', async () => {
    const w = await open({ query: 'Karlsruhe' });
    (document.body.querySelectorAll('.q-radio')[1] as HTMLElement).click();
    await flushPromises();
    expect(w.emitted('choose')).toBeUndefined();
    el('[data-test=geocode-use]')!.click();
    await flushPromises();
    expect(w.emitted('choose')).toEqual([[{ label: 'Karlsruhe Hbf', lon: 8.4, lat: 49.0 }]]);
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false]);
  });

  it('shows the empty result and the translated failure reasons', async () => {
    api.geocoding.search.mockResolvedValueOnce({ results: [] } as never);
    await open({ query: 'Nowhere' });
    expect(el('[data-test=geocode-empty]')).not.toBeNull();
    expect(el('[data-test=geocode-use]')?.hasAttribute('disabled')).toBe(true);
    const limited = new ApiError('swmap_geocoding_failed', 'x', 429);
    limited.reason = 'rate_limited';
    api.geocoding.search.mockRejectedValueOnce(limited as never);
    document.body.innerHTML = '';
    await open({ query: 'Again' });
    expect(el('[data-test=geocode-failure]')?.textContent).toContain('Too many requests');
    api.geocoding.search.mockRejectedValueOnce(new Error('boom') as never);
    document.body.innerHTML = '';
    await open({ query: 'Again' });
    expect(el('[data-test=geocode-failure]')?.textContent).toContain('The address search failed.');
  });

  it('looks up an address for a position', async () => {
    const w = await open({ mode: 'reverse', lon: 8.4, lat: 49 });
    expect(api.geocoding.reverse).toHaveBeenCalledWith(8.4, 49);
    el('[data-test=geocode-use]')!.click();
    await flushPromises();
    expect(w.emitted('choose')?.[0]?.[0]).toMatchObject({ label: 'Kaiserstraße 1' });
    api.geocoding.reverse.mockResolvedValueOnce({ result: null } as never);
    document.body.innerHTML = '';
    await open({ mode: 'reverse', lon: 0, lat: 0 });
    expect(el('[data-test=geocode-empty]')).not.toBeNull();
  });
});

describe('FeatureForm geocoding', () => {
  const feature = () =>
    makeFeature({
      properties: { ...makeFeature().properties, title: 'Schloss', address: 'Schlossbezirk 10' },
    });

  it('applies a confirmed search result as coordinates and a reverse result as address', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() }, attachTo: document.body });
    await w.find('[data-test=geocode-search]').trigger('click');
    await flushPromises();
    expect(api.geocoding.search).toHaveBeenCalledWith('Schlossbezirk 10');
    el('[data-test=geocode-use]')!.click();
    await flushPromises();
    expect(w.emitted('coordinates')).toEqual([[8.4037, 49.0069]]);
    await vi.waitFor(() => expect(el('[data-test=geocode-use]')).toBeNull());
    await w.find('[data-test=geocode-reverse]').trigger('click');
    await flushPromises();
    el('[data-test=geocode-use]')!.click();
    await flushPromises();
    expect(w.emitted('patch')?.at(-1)).toEqual([{ properties: { address: 'Kaiserstraße 1' } }]);
  });

  it('is offered for points only and needs a text to search for', async () => {
    const line = makeFeature({
      geometry: {
        type: 'LineString',
        coordinates: [
          [0, 0],
          [1, 1],
        ],
      },
    });
    expect(
      mount(FeatureForm, { props: { feature: line } })
        .find('[data-test=geocode-search]')
        .exists(),
    ).toBe(false);
    const empty = mount(FeatureForm, {
      props: {
        feature: makeFeature({
          properties: { ...makeFeature().properties, title: '', address: '' },
        }),
      },
    });
    expect(empty.find('[data-test=geocode-search]').attributes('disabled')).toBeDefined();
  });
});

describe('Settings geocoding fields', () => {
  it('saves the server, contact and the allowlist as a list', async () => {
    const w = await mountView(SettingsView);
    const set = async (sel: string, value: string) => {
      const input = w.find<HTMLInputElement>(sel);
      input.element.value = value;
      await input.trigger('input');
    };
    await set('input[data-test=geocoder-url]', 'https://geo.example.org');
    await set('input[data-test=geocoder-contact]', 'maps@example.org');
    await set('textarea[data-test=allowed-hosts]', 'geo.lan\n tiles.lan,  ');
    await w.find('[data-test=save]').trigger('submit');
    await flushPromises();
    expect(api.settings.update).toHaveBeenCalledWith(
      expect.objectContaining({
        geocoder_url: 'https://geo.example.org',
        geocoder_contact: 'maps@example.org',
        allowed_hosts: ['geo.lan', 'tiles.lan'],
      }),
    );
  });
});

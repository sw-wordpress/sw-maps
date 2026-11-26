import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import ProvidersView from '@/features/providers/ProvidersView.vue';
import { ApiError } from '@/api/client';
import { notifier } from './notifier';
import { configure, fakeApi, makeProvider, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
const dialog = (selector: string) => document.body.querySelector<HTMLElement>(selector);
const typeInto = async (selector: string, value: string) => {
  const el = dialog(selector) as HTMLInputElement;
  el.value = value;
  el.dispatchEvent(new Event('input'));
  await flushPromises();
};

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
  api.providers.list.mockResolvedValue([
    makeProvider(),
    makeProvider({
      id: 2,
      name: 'Own tiles',
      enabled: false,
      secret_constant: 'SWMAP_KEY',
      secret_defined: true,
      external_requests: ['tiles.example.org'],
    }),
  ] as never);
});

describe('ProvidersView', () => {
  it('lists providers with hosts, secret constant state and the OSM usage notice', async () => {
    const w = await mountView(ProvidersView);
    const cards = w.findAll('[data-test=provider]');
    expect(cards).toHaveLength(2);
    expect(cards[0]?.text()).toContain('tile.openstreetmap.org');
    expect(cards[1]?.text()).toContain('SWMAP_KEY: Defined in wp-config.php');
    expect(w.find('[data-test=osm-notice]').text()).toContain('no unlimited hosting');
  });

  it('shows the empty state', async () => {
    api.providers.list.mockResolvedValue([] as never);
    expect((await mountView(ProvidersView)).text()).toContain('No providers.');
  });

  it('shows the external requests before a provider is enabled', async () => {
    const w = await mountView(ProvidersView);
    await w.findAll('[data-test=toggle]')[1]?.trigger('click');
    await flushPromises();
    expect(dialog('.q-dialog')?.textContent).toContain('tiles.example.org');
    expect(api.providers.update).not.toHaveBeenCalled();
    dialog('[data-test=confirm]')?.click();
    await flushPromises();
    expect(api.providers.update).toHaveBeenCalledWith(2, { enabled: true });
  });

  it('disables without confirmation', async () => {
    const w = await mountView(ProvidersView);
    await w.findAll('[data-test=toggle]')[0]?.trigger('click');
    await flushPromises();
    expect(api.providers.update).toHaveBeenCalledWith(1, { enabled: false });
  });

  it('tests the connection and reports both outcomes', async () => {
    const w = await mountView(ProvidersView);
    await w.findAll('[data-test=test]')[0]?.trigger('click');
    await flushPromises();
    expect(notifier.success).toHaveBeenCalledWith('Connection successful.');
    expect(w.find('[data-test=test-result]').text()).toBe('Connection successful.');
    api.providers.test.mockResolvedValueOnce({
      ok: false,
      status: 0,
      message: 'Not reachable.',
    } as never);
    await w.findAll('[data-test=test]')[1]?.trigger('click');
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('Connection failed. Not reachable.');
    api.providers.test.mockRejectedValueOnce(new ApiError('x', 'Forbidden', 403) as never);
    await w.findAll('[data-test=test]')[0]?.trigger('click');
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('Forbidden');
  });

  it('creates a provider from the dialog', async () => {
    const w = await mountView(ProvidersView);
    await w.find('[data-test=add]').trigger('click');
    await flushPromises();
    await typeInto('input[data-test=name]', 'Mine');
    await typeInto('input[data-test=url]', 'https://t.example.org/{z}/{x}/{y}.png');
    await typeInto('input[data-test=attribution]', '© Mine');
    await typeInto('input[data-test=secret]', 'SWMAP_MINE');
    dialog('[data-test=save]')?.click();
    await flushPromises();
    expect(api.providers.create).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'Mine',
        secret_constant: 'SWMAP_MINE',
        public_config: expect.objectContaining({
          urlTemplate: 'https://t.example.org/{z}/{x}/{y}.png',
          attribution: '© Mine',
          tileSize: 256,
        }),
      }),
    );
    expect(notifier.success).toHaveBeenCalledWith('Provider saved.');
    expect(w.exists()).toBe(true);
  });

  it('creates a PMTiles provider without tile size, key and secret and fixes the type afterwards', async () => {
    const w = await mountView(ProvidersView);
    await w.find('[data-test=add]').trigger('click');
    await flushPromises();
    const select = w
      .findAllComponents({ name: 'QSelect' })
      .find((c) => c.vm.$attrs['data-test'] === 'type')!;
    select.vm.$emit('update:modelValue', 'pmtiles');
    await flushPromises();
    expect(dialog('input[data-test=secret]')).toBeNull();
    expect(dialog('.q-dialog')?.textContent).toContain('PMTiles URL');
    await typeInto('input[data-test=name]', 'Archive');
    await typeInto('input[data-test=url]', 'https://files.example.org/world.pmtiles');
    await typeInto('input[data-test=attribution]', '© Protomaps');
    dialog('[data-test=save]')?.click();
    await flushPromises();
    const body = api.providers.create.mock.calls[0]![0] as Record<string, unknown>;
    expect(body).toMatchObject({ type: 'pmtiles', name: 'Archive' });
    expect(body).not.toHaveProperty('secret_constant');
    expect(body['public_config']).not.toHaveProperty('tileSize');
    expect(body['public_config']).not.toHaveProperty('publicKey');
  });

  it('edits an existing provider and sends no secret when the constant is cleared', async () => {
    const w = await mountView(ProvidersView);
    await w.findAll('[data-test=edit]')[1]?.trigger('click');
    await flushPromises();
    expect((dialog('input[data-test=name]') as HTMLInputElement).value).toBe('Own tiles');
    expect((dialog('input[data-test=secret]') as HTMLInputElement).value).toBe('SWMAP_KEY');
    await typeInto('input[data-test=secret]', '');
    dialog('[data-test=save]')?.click();
    await flushPromises();
    expect(api.providers.update).toHaveBeenCalledWith(
      2,
      expect.objectContaining({ secret_constant: null }),
    );
    expect(api.providers.update.mock.calls.at(-1)![1]).not.toHaveProperty('type');
  });

  it('shows server side validation errors at the fields and keeps the dialog open', async () => {
    api.providers.create.mockRejectedValue(
      new ApiError('swmap_invalid_param', 'Invalid input.', 400, [
        { field: 'public_config.urlTemplate', message: 'Must be an http(s) URL.' },
        { field: 'secret_constant', message: 'Must be an upper case constant name.' },
      ]) as never,
    );
    const w = await mountView(ProvidersView);
    await w.find('[data-test=add]').trigger('click');
    await flushPromises();
    dialog('[data-test=save]')?.click();
    await flushPromises();
    expect(dialog('.q-dialog')?.textContent).toContain('Must be an http(s) URL.');
    expect(dialog('.q-dialog')?.textContent).toContain('upper case constant name');
    expect(notifier.error).toHaveBeenCalled();
  });

  it('deletes after confirmation', async () => {
    const w = await mountView(ProvidersView);
    await w.findAll('[data-test=delete]')[1]?.trigger('click');
    await flushPromises();
    expect(dialog('.q-dialog')?.textContent).toContain('Own tiles');
    dialog('[data-test=confirm]')?.click();
    await flushPromises();
    expect(api.providers.remove).toHaveBeenCalledWith(2);
    expect(notifier.success).toHaveBeenCalledWith('Provider deleted.');
  });

  it('reports loading failures', async () => {
    api.providers.list.mockRejectedValue(new Error('x') as never);
    await mountView(ProvidersView);
    expect(notifier.error).toHaveBeenCalledWith('Saving failed.');
  });
});

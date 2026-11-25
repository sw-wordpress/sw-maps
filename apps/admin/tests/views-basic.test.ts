import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { notifier } from './notifier';
import DashboardView from '@/features/dashboard/DashboardView.vue';
import SettingsView from '@/features/settings/SettingsView.vue';
import StatusView from '@/features/status/StatusView.vue';
import { ApiError } from '@/api/client';
import { configure, fakeApi, mountView, makeMap, makeProvider } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
});

describe('DashboardView', () => {
  it('shows counts and the enabled provider with its external hosts', async () => {
    api.maps.list.mockResolvedValue({
      items: [
        makeMap({ status: 'published', feature_count: 3 }),
        makeMap({ id: 2, feature_count: 4 }),
      ],
      total: 2,
      totalPages: 1,
    } as never);
    const w = await mountView(DashboardView);
    expect(w.find('[data-test=stat-maps]').text()).toBe('2');
    expect(w.find('[data-test=stat-published]').text()).toBe('1');
    expect(w.find('[data-test=stat-drafts]').text()).toBe('1');
    expect(w.find('[data-test=stat-features]').text()).toBe('7');
    expect(w.find('[data-test=provider-card]').text()).toContain('tile.openstreetmap.org');
  });

  it('shows a hint without enabled provider and hides the card without permission', async () => {
    api.providers.list.mockResolvedValue([makeProvider({ enabled: false })] as never);
    expect((await mountView(DashboardView)).find('[data-test=provider-card]').text()).toContain(
      'No provider enabled',
    );
    configure(['swmap_edit_maps']);
    api.providers.list.mockClear();
    const w = await mountView(DashboardView);
    expect(w.find('[data-test=provider-card]').exists()).toBe(false);
    expect(api.providers.list).not.toHaveBeenCalled();
  });

  it('reports a loading failure', async () => {
    api.maps.list.mockRejectedValue(new ApiError('x', 'Server down', 500) as never);
    await mountView(DashboardView);
    expect(notifier.error).toHaveBeenCalledWith('Server down');
  });
});

describe('SettingsView', () => {
  it('loads, saves and lists the permission mapping', async () => {
    const w = await mountView(SettingsView);
    expect(w.findAll('[data-test=cap-row]')).toHaveLength(5);
    expect(w.text()).toContain('Run imports');
    await w.find('input[data-test=height]').setValue('650');
    await w.find('input[data-test=max-features]').setValue('100');
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(api.settings.update).toHaveBeenCalledWith(
      expect.objectContaining({
        default_height: 650,
        max_features_per_layer: 100,
        delete_data_on_uninstall: false,
      }),
    );
    expect(notifier.success).toHaveBeenCalledWith('Settings saved.');
  });

  it('warns visibly about the data deletion option and reports save errors', async () => {
    api.settings.update.mockRejectedValue(
      new ApiError('swmap_invalid_param', 'Invalid input.', 400, [
        { field: 'default_height', message: 'Invalid value.' },
      ]) as never,
    );
    const w = await mountView(SettingsView);
    await w.find('[data-test=delete-data]').trigger('click');
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(api.settings.update).toHaveBeenCalledWith(
      expect.objectContaining({ delete_data_on_uninstall: true }),
    );
    expect(notifier.error).toHaveBeenCalled();
  });

  it('reports a loading failure', async () => {
    api.settings.get.mockRejectedValue(new Error('x') as never);
    await mountView(SettingsView);
    expect(notifier.error).toHaveBeenCalledWith('Loading failed.');
  });
});

describe('StatusView', () => {
  it('shows versions, schema state and tables', async () => {
    const w = await mountView(StatusView);
    expect(w.find('[data-test=plugin]').text()).toBe('0.1.0');
    expect(w.find('[data-test=schema]').text()).toBe('1 / 1');
    expect(w.find('[data-test=consent]').text()).toBe('Not active');
    expect(w.findAll('[data-test=table]').map((t) => t.text())).toEqual([
      'wp_swmap_maps: OK',
      'wp_swmap_layers: Missing',
    ]);
  });

  it('reports a loading failure', async () => {
    api.status.get.mockRejectedValue(new Error('x') as never);
    await mountView(StatusView);
    expect(notifier.error).toHaveBeenCalled();
  });
});

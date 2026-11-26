import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { notifier } from './notifier';
import { createMemoryHistory, createRouter } from 'vue-router';
import MapsView from '@/features/maps/MapsView.vue';
import { useMapsStore } from '@/stores/maps';
import EmbedDialog from '@/features/maps/EmbedDialog.vue';
import { ApiError } from '@/api/client';
import { configure, fakeApi, makeMap, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
let router: ReturnType<typeof createRouter>;

async function mountMaps() {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/maps/:id', name: 'editor', component: { template: '<div />' } },
    ],
  });
  await router.push('/');
  return mountView(MapsView, {}, { plugins: [router] });
}

const dialog = (selector: string) => document.body.querySelector<HTMLElement>(selector);

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
  api.maps.list.mockResolvedValue({
    items: [
      makeMap({ id: 1, title: 'Alpha', status: 'published' }),
      makeMap({ id: 2, title: '', status: 'draft' }),
    ],
    total: 2,
    totalPages: 1,
  } as never);
});

describe('MapsView', () => {
  it('lists maps with status and counts', async () => {
    const w = await mountMaps();
    const rows = w.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.text()).toContain('Alpha');
    expect(rows[0]?.text()).toContain('Published');
    expect(rows[1]?.text()).toContain('Untitled');
  });

  it('shows the empty state', async () => {
    api.maps.list.mockResolvedValue({ items: [], total: 0, totalPages: 1 } as never);
    expect((await mountMaps()).text()).toContain('No maps yet.');
  });

  it('creates a map and opens the editor', async () => {
    const w = await mountMaps();
    expect(w.find('[data-test=create]').attributes('disabled')).toBeDefined();
    await w.find('input[data-test=new-title]').setValue('  Wanderwege ');
    await w.find('[data-test=create]').trigger('click');
    await flushPromises();
    expect(api.maps.create).toHaveBeenCalledWith({ title: 'Wanderwege' });
    expect(router.currentRoute.value.path).toBe('/maps/5');
  });

  it('shows creation errors and does not navigate', async () => {
    api.maps.create.mockRejectedValue(
      new ApiError('swmap_invalid_param', 'Invalid input.', 400) as never,
    );
    const w = await mountMaps();
    await w.find('input[data-test=new-title]').setValue('X');
    await w.find('input[data-test=new-title]').trigger('keyup.enter');
    await flushPromises();
    expect(notifier.error).toHaveBeenCalled();
    expect(router.currentRoute.value.path).toBe('/');
  });

  it('filters by search text (debounced) and status', async () => {
    vi.useFakeTimers();
    const w = await mountMaps();
    api.maps.list.mockClear();
    await w.find('input[data-test=search]').setValue('alp');
    expect(api.maps.list).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(350);
    expect(api.maps.list).toHaveBeenCalledWith(expect.objectContaining({ search: 'alp', page: 1 }));
    vi.useRealTimers();
    w.findComponent({ name: 'QSelect' }).vm.$emit('update:modelValue', 'draft');
    await flushPromises();
    expect(api.maps.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'draft' }));
  });

  it('starts at page 1 when the status filter changes while a later page is shown', async () => {
    api.maps.list.mockResolvedValue({ items: [makeMap()], total: 45, totalPages: 3 } as never);
    const w = await mountMaps();
    const store = useMapsStore();
    store.page = 3;
    await flushPromises();
    api.maps.list.mockClear();
    w.findComponent({ name: 'QSelect' }).vm.$emit('update:modelValue', 'published');
    await flushPromises();
    expect(store.page).toBe(1);
    expect(api.maps.list).toHaveBeenCalledTimes(1);
    expect(api.maps.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'published', page: 1 }),
    );
  });

  it('duplicates, publishes and unpublishes', async () => {
    const w = await mountMaps();
    await w.findAll('[data-test=duplicate]')[0]?.trigger('click');
    await flushPromises();
    expect(api.maps.duplicate).toHaveBeenCalledWith(1);
    await w.findAll('[data-test=toggle-publish]')[0]?.trigger('click');
    await w.findAll('[data-test=toggle-publish]')[1]?.trigger('click');
    await flushPromises();
    expect(api.maps.update).toHaveBeenNthCalledWith(1, 1, { status: 'draft' });
    expect(api.maps.update).toHaveBeenNthCalledWith(2, 2, { status: 'published' });
  });

  it('hides the publish action without the publish capability', async () => {
    configure(['swmap_edit_maps']);
    expect((await mountMaps()).find('[data-test=toggle-publish]').exists()).toBe(false);
  });

  it('asks before deleting', async () => {
    const w = await mountMaps();
    await w.findAll('[data-test=delete]')[0]?.trigger('click');
    await flushPromises();
    expect(dialog('.q-dialog')?.textContent).toContain('Alpha');
    expect(api.maps.remove).not.toHaveBeenCalled();
    dialog('[data-test=confirm]')?.click();
    await flushPromises();
    expect(api.maps.remove).toHaveBeenCalledWith(1);
  });

  it('shows the embed code and copies it', async () => {
    const writeText = vi.fn(async () => undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const w = await mountMaps();
    await w.findAll('[data-test=embed]')[1]?.trigger('click');
    await flushPromises();
    expect(dialog('[data-test=shortcode]')?.textContent).toBe('[sw_map id="2"]');
    expect(dialog('[data-test=php]')?.textContent).toContain('swmap_render_map( 2 )');
    dialog('[data-test=copy-shortcode]')?.click();
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('[sw_map id="2"]');
  });

  it('shows pagination for many pages', async () => {
    api.maps.list.mockResolvedValue({ items: [makeMap()], total: 45, totalPages: 3 } as never);
    const w = await mountMaps();
    expect(w.find('[data-test=pagination]').exists()).toBe(true);
  });
});

describe('EmbedDialog', () => {
  it('tolerates a blocked clipboard', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: vi.fn(async () => Promise.reject(new Error('blocked'))) },
      configurable: true,
    });
    await mountView(EmbedDialog, { modelValue: true, mapId: null });
    expect(dialog('[data-test=shortcode]')?.textContent).toBe('[sw_map id="0"]');
    dialog('[data-test=copy-shortcode]')?.click();
    await flushPromises();
  });
});

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import EditorToolbar from '@/features/editor/EditorToolbar.vue';
import LayerPanel from '@/features/editor/LayerPanel.vue';
import FeatureForm from '@/features/editor/FeatureForm.vue';
import MapSettingsForm from '@/features/editor/MapSettingsForm.vue';
import { configure, makeFeature, makeLayer, makeMap, makeProvider } from './helpers';

vi.mock('@/utils/media', () => ({
  pickImage: vi.fn(async () => ({
    id: 9,
    url: 'https://example.org/full.jpg',
    sizes: { thumbnail: { url: 'https://example.org/thumb.jpg' } },
  })),
}));
import { pickImage } from '@/utils/media';

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
});

const blur = async (w: ReturnType<typeof mount>, selector: string, value?: string) => {
  const input = w.find<HTMLInputElement>(selector);
  // Quasar only emits blur for a field that was focused before.
  await input.trigger('focus');
  await input.trigger('focusin');
  if (value !== undefined) await input.setValue(value);
  await input.trigger('blur');
  await input.trigger('focusout');
  await new Promise((resolve) => setTimeout(resolve, 5)); // Quasar defers the blur event
};

describe('EditorToolbar', () => {
  const props = {
    tool: 'select' as const,
    canUndo: true,
    canRedo: false,
    dirty: true,
    saving: false,
    mobile: false,
  };

  it('emits tool changes, undo/redo, fit and save', async () => {
    const w = mount(EditorToolbar, { props });
    await w.find('[data-test=tool-point]').trigger('click');
    await w.find('[data-test=tool-line]').trigger('click');
    await w.find('[data-test=tool-polygon]').trigger('click');
    await w.find('[data-test=undo]').trigger('click');
    await w.find('[data-test=fit]').trigger('click');
    await w.find('[data-test=save]').trigger('click');
    expect(w.emitted('update:tool')).toEqual([['point'], ['line'], ['polygon']]);
    expect(w.emitted('undo')).toHaveLength(1);
    expect(w.emitted('fit')).toHaveLength(1);
    expect(w.emitted('save')).toHaveLength(1);
    expect(w.find('[data-test=dirty]').text()).toBe('Unsaved changes');
    expect(w.find('[data-test=tool-select]').attributes('aria-pressed')).toBe('true');
  });

  it('disables unavailable actions and hides the dirty hint when clean', () => {
    const w = mount(EditorToolbar, { props: { ...props, canUndo: false, dirty: false } });
    expect(w.find('[data-test=undo]').attributes('disabled')).toBeDefined();
    expect(w.find('[data-test=redo]').attributes('disabled')).toBeDefined();
    expect(w.find('[data-test=save]').attributes('disabled')).toBeDefined();
    expect(w.find('[data-test=dirty]').exists()).toBe(false);
  });

  it('toggles the mobile preview', async () => {
    const w = mount(EditorToolbar, { props });
    w.findComponent({ name: 'QBtnToggle' }).vm.$emit('update:modelValue', true);
    expect(w.emitted('update:mobile')).toEqual([[true]]);
  });
});

describe('LayerPanel', () => {
  const props = {
    layers: [makeLayer(), makeLayer({ id: 11, title: 'Second', visible: false })],
    features: [
      makeFeature(),
      makeFeature({
        id: 101,
        layer_id: 10,
        properties: { ...makeFeature().properties, title: '' },
        geometry: {
          type: 'LineString',
          coordinates: [
            [0, 0],
            [1, 1],
          ],
        },
      }),
    ],
    activeLayerId: 10,
    selectedId: 100,
  };

  it('lists layers and features with a readable fallback title and shape type', () => {
    const w = mount(LayerPanel, { props });
    expect(w.findAll('[data-test=layer]')).toHaveLength(2);
    const items = w.findAll('[data-test=feature-item]');
    expect(items.map((i) => i.text())).toEqual(['RathausMarker', 'Untitled locationLine']);
    expect(w.text()).toContain('No locations in this layer.');
  });

  it('shows the empty state without layers', () => {
    expect(mount(LayerPanel, { props: { ...props, layers: [], features: [] } }).text()).toContain(
      'No layers yet.',
    );
  });

  it('emits select, add, toggle and rename (only for real changes)', async () => {
    const w = mount(LayerPanel, { props });
    await w.findAll('[data-test=feature-item]')[1]?.trigger('click');
    await w.find('[data-test=add-layer]').trigger('click');
    await w.findAll('[data-test=toggle-layer]')[1]?.trigger('click');
    expect(w.emitted('select')).toEqual([[101]]);
    expect(w.emitted('add-layer')).toHaveLength(1);
    expect(w.emitted('toggle-layer')).toEqual([[11, true]]);
    const title = w.findAll('input[data-test=layer-title]')[0]!;
    await title.trigger('focus');
    await title.trigger('focusin');
    expect(w.emitted('update:activeLayerId')).toEqual([[10]]);
    await title.setValue('Renamed');
    await title.trigger('blur');
    await title.trigger('focusout');
    await new Promise((resolve) => setTimeout(resolve, 5));
    await title.trigger('focus');
    await title.trigger('focusin');
    await title.setValue('   ');
    await title.trigger('blur');
    await title.trigger('focusout');
    await new Promise((resolve) => setTimeout(resolve, 5));
    expect(w.emitted('rename-layer')).toEqual([[10, 'Renamed']]);
  });

  it('asks before deleting a layer', async () => {
    const w = mount(LayerPanel, { props, attachTo: document.body });
    await w.findAll('[data-test=delete-layer]')[1]?.trigger('click');
    await flushPromises();
    expect(document.body.textContent).toContain('Second');
    expect(w.emitted('delete-layer')).toBeUndefined();
    document.body.querySelector<HTMLElement>('[data-test=confirm]')?.click();
    await flushPromises();
    expect(w.emitted('delete-layer')).toEqual([[11]]);
  });
});

describe('FeatureForm', () => {
  const feature = () =>
    makeFeature({
      properties: { ...makeFeature().properties, title: 'Rathaus', link: 'https://example.org' },
      style: { color: '#336699' },
    });

  it('shows the current values and the coordinates of a point', () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    expect(w.find<HTMLInputElement>('input[data-test=title]').element.value).toBe('Rathaus');
    expect(w.find<HTMLInputElement>('input[data-test=lon]').element.value).toBe('8.4');
    expect(w.find<HTMLInputElement>('input[data-test=color]').element.value).toBe('#336699');
  });

  it('commits text changes only when they changed', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    await blur(w, 'input[data-test=title]');
    expect(w.emitted('patch')).toBeUndefined();
    await blur(w, 'input[data-test=title]', 'Neues Rathaus');
    await blur(w, 'textarea[data-test=description]', 'Text');
    await blur(w, 'input[data-test=address]', 'Markt 1');
    expect(w.emitted('patch')).toEqual([
      [{ properties: { title: 'Neues Rathaus' } }],
      [{ properties: { description: 'Text' } }],
      [{ properties: { address: 'Markt 1' } }],
    ]);
  });

  it('validates links client side', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    await blur(w, 'input[data-test=link]', 'javascript:alert(1)');
    expect(w.text()).toContain('Enter an http(s), mailto or tel link.');
    expect(w.emitted('patch')).toBeUndefined();
    await blur(w, 'input[data-test=link]', 'mailto:a@example.org');
    await blur(w, 'input[data-test=link]', '');
    expect(w.emitted('patch')).toEqual([
      [{ properties: { link: 'mailto:a@example.org' } }],
      [{ properties: { link: null } }],
    ]);
  });

  it('validates coordinates and emits valid ones', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    await blur(w, 'input[data-test=lon]', '999');
    expect(w.find('[data-test=coord-error]').exists()).toBe(true);
    expect(w.emitted('coordinates')).toBeUndefined();
    await blur(w, 'input[data-test=lon]', '9.5');
    await blur(w, 'input[data-test=lat]', '50.25');
    expect(w.find('[data-test=coord-error]').exists()).toBe(false);
    expect(w.emitted('coordinates')).toEqual([
      [9.5, 49],
      [9.5, 50.25],
    ]);
  });

  it('has no coordinate inputs for lines and polygons', () => {
    const w = mount(FeatureForm, {
      props: {
        feature: makeFeature({
          geometry: {
            type: 'Polygon',
            coordinates: [
              [
                [0, 0],
                [1, 0],
                [1, 1],
                [0, 0],
              ],
            ],
          },
        }),
      },
    });
    expect(w.find('input[data-test=lon]').exists()).toBe(false);
    expect(w.text()).toContain('Area');
  });

  it('changes icon and colour, and deletes', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    w.findComponent({ name: 'QSelect' }).vm.$emit('update:modelValue', 'builtin:star');
    const color = w.find<HTMLInputElement>('input[data-test=color]');
    color.element.value = '#00ff00';
    await color.trigger('change');
    await w.find('[data-test=delete]').trigger('click');
    expect(w.emitted('patch')).toEqual([
      [{ properties: { icon: 'builtin:star' } }],
      [{ style: { color: '#00ff00' } }],
    ]);
    expect(w.emitted('delete')).toHaveLength(1);
  });

  it('offers a media icon option when one is set', () => {
    const w = mount(FeatureForm, {
      props: {
        feature: makeFeature({ properties: { ...makeFeature().properties, icon: 'media:7' } }),
      },
    });
    expect(w.findComponent({ name: 'QSelect' }).props('options')).toEqual(
      expect.arrayContaining([{ value: 'media:7', label: 'Media library icon' }]),
    );
  });

  it('chooses a media library icon', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    await w.find('[data-test=choose-icon]').trigger('click');
    await flushPromises();
    expect(w.emitted('patch')).toEqual([
      [{ properties: { icon: 'media:9', icon_url: 'https://example.org/thumb.jpg' } }],
    ]);
    vi.mocked(pickImage).mockResolvedValueOnce(null);
    await w.find('[data-test=choose-icon]').trigger('click');
    await flushPromises();
    expect(w.emitted('patch')).toHaveLength(1);
  });

  it('chooses and removes an image through the media library', async () => {
    const w = mount(FeatureForm, { props: { feature: feature() } });
    await w.find('[data-test=choose-image]').trigger('click');
    await flushPromises();
    expect(w.emitted('patch')).toEqual([[{ properties: { image_id: 9 } }]]);
    expect(w.find('img').attributes('src')).toBe('https://example.org/thumb.jpg');
    await w.setProps({
      feature: makeFeature({ properties: { ...makeFeature().properties, image_id: 9 } }),
    });
    await w.find('[data-test=remove-image]').trigger('click');
    expect(w.emitted('patch')?.[1]).toEqual([{ properties: { image_id: null } }]);
    vi.mocked(pickImage).mockResolvedValueOnce(null);
    await w.find('[data-test=choose-image]').trigger('click');
    await flushPromises();
    expect(w.emitted('patch')).toHaveLength(2);
  });
});

describe('MapSettingsForm', () => {
  const props = {
    map: makeMap(),
    providers: [makeProvider(), makeProvider({ id: 2, name: 'Off', enabled: false })],
  };

  it('offers only enabled providers and the default option', () => {
    const w = mount(MapSettingsForm, { props });
    const options = w
      .findAllComponents({ name: 'QSelect' })
      .find((s) =>
        (s.props('options') as Array<{ label: string }>).some(
          (o) => o.label === 'Default provider',
        ),
      )!
      .props('options') as Array<{ label: string }>;
    expect(options.map((o) => o.label)).toEqual(['Default provider', 'OpenStreetMap']);
  });

  it('commits text, viewport and display values on blur', async () => {
    const w = mount(MapSettingsForm, { props });
    await blur(w, 'input[data-test=title]', 'Neuer Titel');
    await blur(w, 'input[data-test=slug]', 'neuer-titel');
    await blur(w, 'textarea[data-test=description]', 'Beschreibung');
    await blur(w, 'input[data-test=lon]', '9');
    await blur(w, 'input[data-test=height]', '650');
    await blur(w, 'input[data-test=aspect]', '16:9');
    await blur(w, 'input[data-test=aspect]', ' ');
    const events = w.emitted('patch')!.map((e) => e[0]);
    expect(events).toEqual([
      { title: 'Neuer Titel' },
      { slug: 'neuer-titel' },
      { description: 'Beschreibung' },
      { viewport: { center: [9, 49], zoom: 10, minZoom: 2, maxZoom: 18 } },
      { settings: { height: 650, aspectRatio: null } },
      { settings: { height: 650, aspectRatio: '16:9' } },
      { settings: { height: 650, aspectRatio: null } },
    ]);
  });

  it('does not emit unchanged text', async () => {
    const w = mount(MapSettingsForm, { props });
    await blur(w, 'input[data-test=title]');
    expect(w.emitted('patch')).toBeUndefined();
  });

  it('toggles interaction settings, auto bounds, status and provider', async () => {
    const w = mount(MapSettingsForm, { props });
    await w.find('[data-test=toggle-scrollZoom]').trigger('click');
    await w.find('[data-test=toggle-clickToLoad]').trigger('click');
    await w.find('[data-test=auto-bounds]').trigger('click');
    for (const select of w.findAllComponents({ name: 'QSelect' }))
      select.vm.$emit('update:modelValue', 'published');
    const events = w.emitted('patch')!.map((e) => e[0]);
    expect(events).toEqual(
      expect.arrayContaining([
        { settings: { scrollZoom: true } },
        { settings: { clickToLoad: true } },
        { viewport: { autoBounds: false } },
        { status: 'published' },
      ]),
    );
  });

  it('emits use-current-view and disables publishing without capability', async () => {
    configure(['swmap_edit_maps']);
    const w = mount(MapSettingsForm, { props });
    await w.find('[data-test=use-view]').trigger('click');
    expect(w.emitted('use-current-view')).toHaveLength(1);
    const status = w
      .findAllComponents({ name: 'QSelect' })
      .find((s) =>
        (s.props('options') as Array<{ value: string }>).some((o) => o.value === 'draft'),
      )!;
    expect(status.props('disable')).toBe(true);
  });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Feature from 'ol/Feature.js';
import Point from 'ol/geom/Point.js';
import type { MapRenderData, StyleMap } from '@sw-maps/map-contracts';
import { MapViewer, OpenLayersRenderer } from '../src';
import { layerInRange, ruleStyle } from '../src/styles';
import { buildFilterBar, matchingIds } from '../src/filters';
import { resolveIcon } from '../src/markers';
import { feature, makeData } from './fixtures';

function dataWithCategories(extra = 0): MapRenderData {
  const data = makeData();
  const features = [
    { ...feature(1, 8.4, 49, 'Alpha hut'), categories: [10] },
    { ...feature(2, 8.5, 49.1, 'Beta lake'), categories: [11] },
    { ...feature(3, 8.6, 49.2, 'Gamma hut'), categories: [10, 11] },
  ];
  for (let i = 0; i < extra; i++) features.push({ ...feature(10 + i), categories: [] });
  data.layers[0]!.features = features;
  data.categories = [
    { id: 10, title: 'Huts', style: { color: '#00aa00', icon: 'builtin:home' } },
    { id: 11, title: 'Lakes', style: { color: '#0000cc' } },
  ];
  return data;
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('matchingIds', () => {
  it('matches text (title, address, description) and categories together', () => {
    const data = dataWithCategories();
    expect([...matchingIds(data, { query: '', categories: new Set() }).ids]).toEqual([1, 2, 3]);
    expect(matchingIds(data, { query: '', categories: new Set() }).active).toBe(false);
    expect([...matchingIds(data, { query: ' HUT ', categories: new Set() }).ids]).toEqual([1, 3]);
    expect([...matchingIds(data, { query: '', categories: new Set([11]) }).ids]).toEqual([2, 3]);
    expect([...matchingIds(data, { query: 'hut', categories: new Set([11]) }).ids]).toEqual([3]);
    expect(matchingIds(data, { query: 'street 1', categories: new Set() }).ids.size).toBe(3);
    expect(matchingIds(data, { query: 'zzz', categories: new Set() }).active).toBe(true);
  });
});

describe('buildFilterBar', () => {
  it('returns nothing for a map without categories and few locations', () => {
    expect(buildFilterBar(document, makeData(), vi.fn())).toBeNull();
  });

  it('renders toggle chips with marker images and a search field for many locations', () => {
    const onChange = vi.fn();
    const bar = buildFilterBar(document, dataWithCategories(3), onChange)!;
    document.body.append(bar.element);
    const chips = bar.element.querySelectorAll<HTMLButtonElement>('.swmap__chip');
    expect(chips).toHaveLength(2);
    expect(chips[0]!.textContent).toBe('Huts');
    expect(chips[0]!.querySelector('img')!.src).toContain('data:image/svg+xml');
    expect(bar.element.querySelector('[role=group]')!.getAttribute('aria-label')).toBe(
      'Filter by category',
    );
    chips[0]!.click();
    expect(chips[0]!.getAttribute('aria-pressed')).toBe('true');
    expect(onChange).toHaveBeenLastCalledWith({ query: '', categories: new Set([10]) });
    chips[0]!.click();
    expect(chips[0]!.getAttribute('aria-pressed')).toBe('false');
    const input = bar.element.querySelector<HTMLInputElement>('input[type=search]')!;
    input.value = 'lake';
    input.dispatchEvent(new Event('input'));
    input.value = 'lake x';
    input.dispatchEvent(new Event('input'));
    expect(onChange).toHaveBeenCalledTimes(2);
    vi.advanceTimersByTime(200);
    expect(onChange).toHaveBeenCalledTimes(3);
    expect(onChange).toHaveBeenLastCalledWith({ query: 'lake x', categories: new Set() });
    input.value = 'late';
    input.dispatchEvent(new Event('input'));
    bar.destroy();
    vi.advanceTimersByTime(500);
    expect(onChange).toHaveBeenCalledTimes(3);
    expect(document.querySelector('.swmap__filters')).toBeNull();
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(500);
    expect(onChange).toHaveBeenCalledTimes(3);
  });

  it('offers only the search field when no categories exist', () => {
    const data = dataWithCategories(3);
    data.categories = [];
    const bar = buildFilterBar(document, data, vi.fn())!;
    expect(bar.element.querySelector('.swmap__legend')).toBeNull();
    expect(bar.element.querySelector('input[type=search]')).not.toBeNull();
  });
});

describe('category styling', () => {
  it('uses the category colour and icon unless the feature chooses its own', () => {
    const base = feature(1).properties;
    const withCategory = decodeURIComponent(
      resolveIcon(base, {}, {}, { color: '#00aa00', icon: 'builtin:home' }).src,
    );
    expect(withCategory).toContain('#00aa00');
    expect(withCategory).toContain('M12 5.5l5 4.2');
    const own = decodeURIComponent(
      resolveIcon(
        { ...base, icon: 'builtin:star' },
        { color: '#111111' },
        {},
        { color: '#00aa00', icon: 'builtin:home' },
      ).src,
    );
    expect(own).toContain('#111111');
    expect(own).not.toContain('M12 5.5l5 4.2');
    expect(decodeURIComponent(resolveIcon(base, {}, {}, { icon: '' }).src)).toContain('<circle');
  });
});

describe('viewer filter integration', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.stubGlobal('IntersectionObserver', undefined);
  });
  afterEach(() => vi.unstubAllGlobals());

  function html(data: MapRenderData): string {
    const items = data.layers[0]!.features.map(
      (f) => `<li data-feature-id="${f.id}"><span>${f.properties.title}</span></li>`,
    ).join('');
    return `<div class="swmap" data-swmap="1" data-lazy="0"><div class="swmap__stage"></div><ul class="swmap__list">${items}</ul><script type="application/json" class="swmap__data">${JSON.stringify(data)}</script></div>`;
  }

  it('filters list items and the map, announces an empty result and clears it again', () => {
    document.body.innerHTML = html(dataWithCategories(3));
    const root = document.querySelector<HTMLElement>('.swmap')!;
    const renderer = new OpenLayersRenderer({ popups: true });
    const setVisible = vi.spyOn(renderer, 'setVisibleIds');
    const viewer = new MapViewer(root, {
      createRenderer: () => renderer,
      hasConsent: () => true,
      onConsentChange: () => () => undefined,
    });
    viewer.start();
    expect(root.querySelector('.swmap__filters')).not.toBeNull();
    const chip = root.querySelector<HTMLButtonElement>('.swmap__chip')!;
    chip.click();
    const hidden = [...root.querySelectorAll<HTMLElement>('li[data-feature-id]')]
      .filter((li) => li.hidden)
      .map((li) => li.dataset['featureId']);
    expect(hidden).toEqual(['2', '10', '11', '12']);
    expect([...(setVisible.mock.calls.at(-1)![0] as Set<number>)]).toEqual([1, 3]);
    const input = root.querySelector<HTMLInputElement>('input[type=search]')!;
    input.value = 'nothing like this';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(200);
    expect(root.querySelector('.swmap__empty')!.textContent).toBe('No locations found.');
    input.value = 'zzz again';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(200);
    expect(root.querySelectorAll('.swmap__empty')).toHaveLength(1);
    chip.click();
    input.value = '';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(200);
    expect(root.querySelector('.swmap__empty')).toBeNull();
    expect(root.querySelectorAll('li[hidden]')).toHaveLength(0);
    expect(setVisible.mock.calls.at(-1)![0]).toBeNull();
    viewer.destroy();
    expect(root.querySelector('.swmap__filters')).toBeNull();
  });

  it('keeps the filter when the renderer starts later and works without a list', () => {
    const data = dataWithCategories(3);
    document.body.innerHTML = html(data).replace(/<ul[\s\S]*<\/ul>/, '');
    const root = document.querySelector<HTMLElement>('.swmap')!;
    const viewer = new MapViewer(root, {
      createRenderer: () => new OpenLayersRenderer({ popups: true }),
      hasConsent: () => true,
      onConsentChange: () => () => undefined,
    });
    viewer.start();
    root.querySelector<HTMLInputElement>('input[type=search]')!.value = 'zzz';
    root.querySelector<HTMLInputElement>('input[type=search]')!.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(200);
    expect(root.querySelector('.swmap__empty')).not.toBeNull();
  });
});

describe('renderer visibility', () => {
  it('hides filtered features, deselects a hidden selection and shows all again', () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const target = document.querySelector<HTMLElement>('.swmap__stage')!;
    const r = new OpenLayersRenderer({ popups: true });
    const data = dataWithCategories();
    data.settings = { ...data.settings, cluster: true };
    r.mount(target, { data });
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    r.selectFeature(2);
    r.setVisibleIds([1, 3]);
    expect(events).toEqual([2, null]);
    const visible = (id: number) =>
      (r as unknown as { isVisible(id: number): boolean }).isVisible(id);
    expect([visible(1), visible(2), visible(3)]).toEqual([true, false, true]);
    r.setVisibleIds(null);
    expect([visible(1), visible(2)]).toEqual([true, true]);
    r.setVisibleIds([]);
    expect(visible(1)).toBe(false);
  });
});

describe('layer zoom limits and draw order', () => {
  it('applies minZoom/maxZoom per layer and treats an unknown zoom as visible', () => {
    expect(layerInRange({}, 5)).toBe(true);
    expect(layerInRange({ minZoom: 8 }, 7.9)).toBe(false);
    expect(layerInRange({ minZoom: 8 }, 8)).toBe(true);
    expect(layerInRange({ maxZoom: 12 }, 12)).toBe(true);
    expect(layerInRange({ maxZoom: 12 }, 12.1)).toBe(false);
    expect(layerInRange({ minZoom: 8, maxZoom: 12 }, null)).toBe(true);
    expect(layerInRange({ minZoom: '8' }, 1)).toBe(true);
  });

  it('hides features of out-of-range layers, also from clusters, and refreshes on zoom changes', () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const data = dataWithCategories();
    data.settings = { ...data.settings, cluster: true };
    data.layers[0]!.style = { minZoom: 12 };
    data.layers.push({ id: 2, title: 'Top', style: {}, features: [feature(50, 8.7, 49.3)] });
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(document.querySelector<HTMLElement>('.swmap__stage')!, { data });
    const internals = r as unknown as {
      clusterSource: { refresh(): void };
      map: {
        getView(): { setZoom(z: number): void; dispatchEvent(e: string): void };
        dispatchEvent(e: string): void;
      };
      source: { getFeatures(): Array<{ get(k: string): number }> };
      vectorLayer: { getStyleFunction(): (f: unknown, r: number) => unknown };
      metas: Map<number, unknown>;
    };
    const refresh = vi.spyOn(internals.clusterSource, 'refresh');
    internals.map.getView().setZoom(6);
    internals.map.dispatchEvent('moveend');
    expect(refresh).toHaveBeenCalledTimes(1);
    internals.map.dispatchEvent('moveend');
    expect(refresh).toHaveBeenCalledTimes(1);
    const hidden = internals.source.getFeatures().find((f) => f.get('swmapId') === 1)!;
    const visible = internals.source.getFeatures().find((f) => f.get('swmapId') === 50)!;
    const geometryFunction = (
      internals.clusterSource as unknown as { geometryFunction(f: unknown): unknown }
    ).geometryFunction;
    expect(geometryFunction(hidden)).toBeNull();
    expect(geometryFunction(visible)).not.toBeNull();
    internals.map.getView().setZoom(13);
    expect(geometryFunction(hidden)).not.toBeNull();
    // Later layers are drawn above earlier ones.
    const top = (internals.metas.get(50) as { order: number }).order;
    const bottom = (internals.metas.get(1) as { order: number }).order;
    expect(top).toBeGreaterThan(bottom);
  });
});

describe('layer style rules', () => {
  it('picks the first rule that matches a category of the feature', () => {
    const style: StyleMap = {
      rules: [
        { categoryId: 5, style: { color: '#111111' } },
        { categoryId: 6, style: { color: '#222222', weight: 7 } },
        { categoryId: 6, style: { color: '#333333' } },
      ],
    };
    expect(ruleStyle(style, [6, 5])).toEqual({ color: '#111111' });
    expect(ruleStyle(style, [6])).toEqual({ color: '#222222', weight: 7 });
    expect(ruleStyle(style, [9])).toEqual({});
    expect(ruleStyle({}, [5])).toEqual({});
    expect(ruleStyle({ rules: 'x' as never }, [5])).toEqual({});
    expect(ruleStyle({ rules: [{ categoryId: 5, style: null }] as never }, [5])).toEqual({});
  });

  it('lets a rule override the category and the feature override the rule', () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const data = dataWithCategories();
    data.layers[0]!.style = { rules: [{ categoryId: 10, style: { color: '#ff0000' } }] };
    data.layers[0]!.features[1]!.style = { color: '#0000ff' };
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(document.querySelector<HTMLElement>('.swmap__stage')!, { data });
    const metas = (
      r as unknown as { metas: Map<number, { categoryStyle: Record<string, unknown> }> }
    ).metas;
    expect(metas.get(1)!.categoryStyle['color']).toBe('#ff0000');
    expect(metas.get(1)!.categoryStyle['icon']).toBe('builtin:home');
    expect(metas.get(2)!.categoryStyle['color']).toBe('#0000cc');
  });
});

describe('cluster refresh while features are added', () => {
  it('knows the layer of a feature before the cluster source sees it', () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const data = dataWithCategories();
    data.settings = { ...data.settings, cluster: true };
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(document.querySelector<HTMLElement>('.swmap__stage')!, { data });
    const inner = r as unknown as {
      metas: Map<number, unknown>;
      source: { on(e: string, f: (ev: { feature: Feature }) => void): void };
    };
    const seen: boolean[] = [];
    inner.source.on('addfeature', (event) =>
      seen.push(inner.metas.has(event.feature.get('swmapId') as number)),
    );
    const next = dataWithCategories();
    next.settings = { ...next.settings, cluster: true };
    next.layers[0]!.features.push({ ...feature(77, 8.41, 49.01), categories: [] });
    r.update(next);
    expect(seen).toEqual([true]);
  });
});

describe('tooltip', () => {
  it('shows the title of a hovered marker, hides it elsewhere and for open popups', () => {
    document.body.innerHTML = '<div class="swmap__stage"></div>';
    const r = new OpenLayersRenderer({ popups: true });
    r.mount(document.querySelector<HTMLElement>('.swmap__stage')!, { data: dataWithCategories() });
    const map = (
      r as unknown as {
        map: {
          forEachFeatureAtPixel: unknown;
          dispatchEvent(e: unknown): void;
          getOverlays(): {
            getArray(): Array<{ getPosition(): unknown; getElement(): HTMLElement | undefined }>;
          };
        };
      }
    ).map;
    const tooltip = () =>
      map
        .getOverlays()
        .getArray()
        .find((o) => o.getElement()?.className === 'swmap-tooltip')!;
    const hover = (hit: unknown) => {
      map.forEachFeatureAtPixel = (() => hit) as never;
      map.dispatchEvent({
        type: 'pointermove',
        pixel: [1, 1],
        coordinate: [10, 20],
        dragging: false,
      });
    };
    const marker = (id: number) => {
      const f = new Feature(new Point([0, 0]));
      f.set('swmapId', id);
      return f;
    };
    hover(marker(1));
    expect(tooltip().getElement()!.textContent).toBe('Alpha hut');
    expect(tooltip().getPosition()).toEqual([10, 20]);
    hover(new Feature({ features: [marker(2)] }));
    expect(tooltip().getElement()!.textContent).toBe('Beta lake');
    hover(new Feature({ features: [marker(1), marker(2)] }));
    expect(tooltip().getPosition()).toBeUndefined();
    hover(marker(1));
    r.selectFeature(1);
    hover(marker(1));
    expect(tooltip().getPosition()).toBeUndefined();
    hover(undefined);
    expect(tooltip().getPosition()).toBeUndefined();
    map.dispatchEvent({ type: 'pointermove', pixel: [1, 1], coordinate: [1, 1], dragging: true });
  });
});

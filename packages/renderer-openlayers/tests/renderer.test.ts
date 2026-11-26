import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Collection from 'ol/Collection.js';
import Feature from 'ol/Feature.js';
import MapBrowserEvent from 'ol/MapBrowserEvent.js';
import Point from 'ol/geom/Point.js';
import { DrawEvent } from 'ol/interaction/Draw.js';
import type Draw from 'ol/interaction/Draw.js';
import { ModifyEvent } from 'ol/interaction/Modify.js';
import type Modify from 'ol/interaction/Modify.js';
import { fromLonLat } from 'ol/proj.js';
import type { StyleMap } from '@sw-maps/map-contracts';
import { EditableOpenLayersRenderer, OpenLayersRenderer } from '../src';
import { feature, makeData } from './fixtures';

let target: HTMLElement;

beforeEach(() => {
  document.body.innerHTML = '';
  target = document.createElement('div');
  target.className = 'swmap__stage';
  document.body.append(target);
});

afterEach(() => {
  vi.restoreAllMocks();
});

function mounted(data = makeData(), popups = true): OpenLayersRenderer {
  const r = new OpenLayersRenderer({ popups });
  r.mount(target, { data });
  return r;
}

interface ClusterInternals {
  map: {
    forEachFeatureAtPixel: unknown;
    getLayers(): { getLength(): number };
    getView(): { fit: unknown };
  };
  clusterLayer: { getStyleFunction(): (f: Feature, r: number) => unknown } | null;
  vectorLayer: { getStyleFunction(): (f: Feature, r: number) => unknown };
  onMapClick(e: unknown): void;
}

describe('clustering', () => {
  const clusterData = () => {
    const data = makeData();
    data.settings = { ...data.settings, cluster: true };
    data.layers[0]!.features = [
      feature(1, 8.4, 49),
      feature(2, 8.4001, 49.0001),
      feature(3, 8.5, 49.1),
      {
        id: 4,
        geometry: {
          type: 'LineString',
          coordinates: [
            [8, 49],
            [8.1, 49.1],
          ],
        },
        properties: feature(1).properties,
        style: {},
      },
    ];
    return data;
  };
  const internals = (r: OpenLayersRenderer) => r as unknown as ClusterInternals;
  const group = (...ids: number[]) =>
    new Feature({
      features: ids.map((id) => {
        const f = new Feature(new Point(fromLonLat([8.4 + id * 0.01, 49])));
        f.set('swmapId', id);
        return f;
      }),
    });

  it('adds a cluster layer only when enabled and keeps lines in the plain layer', () => {
    expect(internals(mounted()).clusterLayer).toBeNull();
    const r = mounted(clusterData());
    const i = internals(r);
    expect(i.clusterLayer).not.toBeNull();
    expect(i.map.getLayers().getLength()).toBe(3);
    const point = new Feature(new Point(fromLonLat([8.4, 49])));
    point.set('swmapId', 1);
    expect(i.vectorLayer.getStyleFunction()(point, 1)).toBeUndefined();
    const line = r['source' as keyof typeof r] as unknown as { getFeatures(): Feature[] };
    const lineFeature = line.getFeatures().find((f) => f.get('swmapId') === 4)!;
    expect(i.vectorLayer.getStyleFunction()(lineFeature, 1)).toBeDefined();
  });

  it('draws groups as a numbered circle and single markers with their own style', () => {
    const i = internals(mounted(clusterData()));
    const style = i.clusterLayer!.getStyleFunction()(group(1, 2, 3), 1) as {
      getText(): { getText(): string };
    };
    expect(style.getText().getText()).toBe('3');
    expect(i.clusterLayer!.getStyleFunction()(group(1, 2, 3), 1)).toBe(style);
    const single = i.clusterLayer!.getStyleFunction()(group(1), 1) as {
      getImage(): unknown;
      getText(): unknown;
    };
    expect(single.getImage()).toBeDefined();
    expect(single.getText()).toBeNull();
  });

  it('zooms into a clicked group and selects a clicked single marker', () => {
    const r = mounted(clusterData());
    const i = internals(r);
    const fit = vi.fn();
    i.map.getView().fit = fit as never;
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    i.map.forEachFeatureAtPixel = (() => group(1, 2)) as never;
    i.onMapClick({ pixel: [0, 0] });
    expect(fit).toHaveBeenCalledTimes(1);
    expect(events).toEqual([]);
    i.map.forEachFeatureAtPixel = (() => group(3)) as never;
    i.onMapClick({ pixel: [0, 0] });
    expect(events).toEqual([3]);
    i.map.forEachFeatureAtPixel = (() => new Feature({ features: [] })) as never;
    i.onMapClick({ pixel: [0, 0] });
    expect(events).toEqual([3, null]);
  });

  it('lists coincident members instead of zooming and opens the chosen one', () => {
    const data = clusterData();
    data.layers[0]!.features = [
      feature(1, 8.4, 49, 'Alpha'),
      feature(2, 8.4, 49, 'Beta'),
      feature(3, 8.4, 49, ''),
    ];
    const r = mounted(data);
    const i = internals(r);
    const fit = vi.fn();
    i.map.getView().fit = fit as never;
    const member = (id: number) => {
      const f = new Feature(new Point(fromLonLat([8.4, 49])));
      f.set('swmapId', id);
      return f;
    };
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    i.map.forEachFeatureAtPixel = (() =>
      new Feature({ features: [member(1), member(2), member(3)] })) as never;
    i.onMapClick({ pixel: [0, 0] });
    expect(fit).not.toHaveBeenCalled();
    const buttons = [...target.querySelectorAll<HTMLButtonElement>('.swmap-chooser__list button')];
    expect(buttons.map((b) => b.textContent)).toEqual(['Alpha', 'Beta', '—']);
    buttons[1]!.click();
    expect(events).toEqual([2]);
    expect(target.querySelector('.swmap-popup__title')?.textContent).toBe('Beta');
    i.onMapClick({ pixel: [0, 0] });
    target.querySelector<HTMLButtonElement>('.swmap-chooser .swmap-popup__close')!.click();
    expect(events.at(-1)).toBeNull();
    expect(target.querySelector('.swmap-chooser')).toBeNull();
  });

  it('lists members at the maximum zoom even when they are not at the same spot', () => {
    const r = mounted(clusterData());
    const i = internals(r);
    r.setView({ zoom: 2 });
    const view = i.map.getView() as unknown as { getMaxZoom(): number; getZoom(): number };
    vi.spyOn(view, 'getZoom').mockReturnValue(view.getMaxZoom());
    const a = new Feature(new Point(fromLonLat([8.4, 49])));
    const b = new Feature(new Point(fromLonLat([8.4001, 49.0001])));
    a.set('swmapId', 1);
    b.set('swmapId', 2);
    i.map.forEachFeatureAtPixel = (() => new Feature({ features: [a, b] })) as never;
    i.onMapClick({ pixel: [0, 0] });
    expect(target.querySelectorAll('.swmap-chooser__list button')).toHaveLength(2);
  });

  it('rebuilds when clustering is switched off and on', () => {
    const r = mounted(clusterData());
    const off = clusterData();
    off.settings = { ...off.settings, cluster: false };
    r.update(off);
    expect(internals(r).clusterLayer).toBeNull();
    r.update(clusterData());
    expect(internals(r).clusterLayer).not.toBeNull();
    expect(target.querySelectorAll('.swmap__canvas')).toHaveLength(1);
  });
});

describe('OpenLayersRenderer', () => {
  it('requires mount before use', () => {
    const r = new OpenLayersRenderer();
    expect(() => r.getView()).toThrow('not mounted');
    expect(() => r.update(makeData())).toThrow('not mounted');
  });

  it('renders attribution, fullscreen and zoom/scale controls', () => {
    Object.defineProperty(document, 'fullscreenEnabled', { value: true, configurable: true });
    mounted();
    expect(target.querySelector('.swmap__attribution a')?.getAttribute('href')).toBe(
      'https://tiles.example.org/copyright',
    );
    expect(target.querySelector('.swmap__fullscreen')?.getAttribute('aria-label')).toBe(
      'Fullscreen',
    );
    expect(target.querySelector('.ol-zoom')).not.toBeNull();
    expect(target.querySelector('.ol-scale-line')).not.toBeNull();
  });

  it('omits controls that are disabled and works without provider', () => {
    const data = makeData({ provider: null });
    data.settings = { ...data.settings, zoomControls: false, scale: false, fullscreen: false };
    mounted(data);
    expect(target.querySelector('.ol-zoom')).toBeNull();
    expect(target.querySelector('.ol-scale-line')).toBeNull();
    expect(target.querySelector('.swmap__attribution')).toBeNull();
    expect(target.querySelector('.swmap__fullscreen')).toBeNull();
  });

  it('toggles fullscreen state and keeps the attribution inside the stage', async () => {
    Object.defineProperty(document, 'fullscreenEnabled', { value: true, configurable: true });
    target.requestFullscreen = vi.fn(async () => {
      Object.defineProperty(document, 'fullscreenElement', { value: target, configurable: true });
      document.dispatchEvent(new Event('fullscreenchange'));
    });
    document.exitFullscreen = vi.fn(async () => {
      Object.defineProperty(document, 'fullscreenElement', { value: null, configurable: true });
      document.dispatchEvent(new Event('fullscreenchange'));
    });
    mounted();
    const button = target.querySelector<HTMLButtonElement>('.swmap__fullscreen');
    button?.click();
    await Promise.resolve();
    expect(target.classList.contains('is-fullscreen')).toBe(true);
    expect(button?.getAttribute('aria-label')).toBe('Exit fullscreen');
    expect(target.querySelector('.swmap__attribution')).not.toBeNull();
    button?.click();
    await Promise.resolve();
    expect(target.classList.contains('is-fullscreen')).toBe(false);
  });

  it('gets and sets the view in WGS84', () => {
    const r = mounted();
    r.setView({ center: [9, 50], zoom: 6 });
    const v = r.getView();
    expect(v.center[0]).toBeCloseTo(9, 5);
    expect(v.center[1]).toBeCloseTo(50, 5);
    expect(v.zoom).toBe(6);
    r.setView({ zoom: 7 });
    expect(r.getView().zoom).toBe(7);
    r.fitBounds([8, 49, 9, 50], { padding: 10, maxZoom: 9 });
    r.fitBounds([8, 49, 9, 50]);
  });

  it('auto bounds fit all features (single point uses a close zoom)', () => {
    const data = makeData();
    data.viewport = { ...data.viewport, autoBounds: true, zoom: 3 };
    const r = mounted(data);
    expect(r.getView().zoom).toBeGreaterThan(3);
    const single = makeData();
    single.viewport = { ...single.viewport, autoBounds: true, zoom: 3 };
    single.layers = [{ id: 1, title: 'L', style: {}, features: [feature(1)] }];
    const s = new OpenLayersRenderer();
    const t2 = document.createElement('div');
    document.body.append(t2);
    s.mount(t2, { data: single });
    expect(s.getView().zoom).toBeLessThanOrEqual(14);
    const empty = makeData();
    empty.viewport = { ...empty.viewport, autoBounds: true };
    empty.layers = [];
    const e = new OpenLayersRenderer();
    const t3 = document.createElement('div');
    document.body.append(t3);
    e.mount(t3, { data: empty });
    expect(e.getView().zoom).toBe(10);
  });

  it('selects features, opens a text-only popup and emits select', () => {
    const data = makeData();
    data.layers[0]!.features[0]!.properties.title = '<b>Bold</b>';
    const r = mounted(data);
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    r.selectFeature(1, { centre: true });
    expect(target.querySelector('.swmap-popup__title')?.textContent).toBe('<b>Bold</b>');
    expect(target.querySelector('.swmap-popup b')).toBeNull();
    r.selectFeature(999);
    expect(target.querySelector('.swmap-popup')).toBeNull();
    r.selectFeature(2);
    target.querySelector<HTMLButtonElement>('.swmap-popup__close')?.click();
    expect(events).toEqual([1, null, 2, null]);
  });

  it('does not open popups for the editor configuration', () => {
    const r = mounted(makeData(), false);
    r.selectFeature(1);
    expect(target.querySelector('.swmap-popup')).toBeNull();
  });

  it('update keeps unchanged features, applies changes and clears a deleted selection', () => {
    const r = mounted();
    r.selectFeature(1);
    const data = makeData();
    data.layers[0]!.features = [feature(1, 8.4, 49, 'Renamed')];
    r.update(data);
    expect(target.querySelector('.swmap-popup__title')?.textContent).toBe('Renamed');
    const data2 = makeData();
    data2.layers[0]!.features = [feature(2)];
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    r.update(data2);
    expect(events).toEqual([null]);
    data2.provider = {
      ...data2.provider!,
      urlTemplate: 'https://other.example.org/{z}/{x}/{y}.png',
      attribution: 'Other',
    };
    r.update(data2);
    expect(target.querySelector('.swmap__attribution')?.textContent).toBe('Other');
  });

  it('rebuilds around the current view when interaction settings change', () => {
    const r = mounted();
    r.setView({ zoom: 12 });
    const data = makeData();
    data.settings = { ...data.settings, scrollZoom: true, scale: false };
    r.update(data);
    expect(r.getView().zoom).toBe(12);
    expect(target.querySelector('.ol-scale-line')).toBeNull();
    expect(target.querySelectorAll('.swmap__canvas')).toHaveLength(1);
  });

  it('rebuilds the tile source when the provider grid settings change', () => {
    const r = mounted();
    const first = (r as unknown as { tileLayer: unknown }).tileLayer;
    r.update(makeData());
    expect((r as unknown as { tileLayer: unknown }).tileLayer).toBe(first);
    const bigger = makeData();
    bigger.provider = { ...bigger.provider!, tileSize: 512, maxZoom: 17 };
    r.update(bigger);
    const next = (
      r as unknown as { tileLayer: { getSource(): { getTileGrid(): { getMaxZoom(): number } } } }
    ).tileLayer;
    expect(next).not.toBe(first);
    expect(next.getSource().getTileGrid().getMaxZoom()).toBe(17);
  });

  it('emits viewchange and a single error after repeated tile failures', () => {
    const r = mounted();
    const errors: Error[] = [];
    r.on('error', (e) => errors.push(e));
    const source = (
      r as unknown as { tileLayer: { getSource(): { dispatchEvent(e: unknown): void } } }
    ).tileLayer.getSource();
    for (let i = 0; i < 6; i++) source.dispatchEvent({ type: 'tileloaderror' });
    expect(errors).toHaveLength(1);
    expect(r.tileErrorCount).toBe(6);
  });

  it('skips features with invalid geometry and destroys cleanly', () => {
    const data = makeData();
    data.layers[0]!.features.push({
      ...feature(3),
      geometry: { type: 'Nope' as never, coordinates: [] },
    });
    const r = mounted(data);
    r.destroy();
    expect(target.querySelector('.swmap__canvas')).toBeNull();
    expect(() => r.destroy()).not.toThrow();
  });

  it('re-mounting replaces the previous map', () => {
    const r = mounted();
    r.mount(target, { data: makeData() });
    expect(target.querySelectorAll('.swmap__canvas')).toHaveLength(1);
  });

  it('draws line and polygon styles without errors', () => {
    const data = makeData();
    data.layers[0]!.features = [
      {
        id: 10,
        geometry: {
          type: 'LineString',
          coordinates: [
            [8, 49],
            [8.2, 49.1],
          ],
        },
        properties: feature(10).properties,
        style: { color: '#336699', weight: 5 },
      },
      {
        id: 11,
        geometry: {
          type: 'Polygon',
          coordinates: [
            [
              [8, 49],
              [8.2, 49],
              [8.2, 49.2],
              [8, 49],
            ],
          ],
        },
        properties: feature(11).properties,
        style: { fillOpacity: 0.5 },
      },
    ];
    const r = mounted(data);
    r.selectFeature(10);
    r.selectFeature(11);
    expect(r.getView().zoom).toBeGreaterThan(0);
  });
});

describe('line and area styles', () => {
  type StyleFn = (
    f: Feature,
    r: number,
  ) => {
    getStroke(): { getLineDash(): number[] | null; getWidth(): number; getColor(): string };
    getImage(): { getOpacity(): number };
  };
  function styleOf(style: StyleMap, layerStyle: StyleMap = {}, geometry = 'LineString') {
    const data = makeData();
    data.layers[0]!.style = layerStyle;
    data.layers[0]!.features = [
      {
        id: 20,
        geometry:
          geometry === 'Point'
            ? { type: 'Point', coordinates: [8, 49] }
            : {
                type: 'LineString',
                coordinates: [
                  [8, 49],
                  [8.2, 49.1],
                ],
              },
        properties: feature(20).properties,
        style,
      },
    ];
    const r = mounted(data);
    const fn = (
      r as unknown as { vectorLayer: { getStyleFunction(): StyleFn } }
    ).vectorLayer.getStyleFunction();
    const source = (r as unknown as { source: { getFeatures(): Feature[] } }).source;
    return { style: fn(source.getFeatures()[0]!, 1), fn, feature: source.getFeatures()[0]! };
  }

  it('applies weight, pattern and opacity from the feature, falling back to the layer', () => {
    const own = styleOf({
      color: '#ff0000',
      weight: 6,
      dash: 'dashed',
      opacity: 0.5,
    }).style.getStroke();
    expect(own.getWidth()).toBe(6);
    expect(own.getLineDash()).toEqual([12, 8]);
    expect(own.getColor()).toBe('rgba(255, 0, 0, 0.5)');
    const layer = styleOf({}, { color: '#00ff00', dash: 'dotted', weight: 2 }).style.getStroke();
    expect([layer.getWidth(), layer.getLineDash(), layer.getColor()]).toEqual([
      2,
      [2, 8],
      '#00ff00',
    ]);
    const plain = styleOf({ dash: 'wavy', weight: 500 }).style.getStroke();
    expect([plain.getLineDash(), plain.getWidth()]).toEqual([null, 20]);
  });

  it('applies every attribute of a matching layer rule, below the feature own style', () => {
    const data = makeData();
    data.categories = [{ id: 7, title: 'C', style: {} }];
    data.layers[0]!.style = {
      weight: 2,
      rules: [
        {
          categoryId: 7,
          style: { weight: 12, dash: 'dotted', opacity: 0.5, fillOpacity: 0.7, color: '#ff0000' },
        },
      ],
    };
    data.layers[0]!.features = [
      {
        id: 40,
        geometry: {
          type: 'LineString',
          coordinates: [
            [8, 49],
            [8.1, 49.1],
          ],
        },
        properties: feature(40).properties,
        style: {},
        categories: [7],
      },
      {
        id: 41,
        geometry: {
          type: 'LineString',
          coordinates: [
            [8, 49],
            [8.1, 49.1],
          ],
        },
        properties: feature(41).properties,
        style: { weight: 4 },
        categories: [7],
      },
      {
        id: 42,
        geometry: {
          type: 'LineString',
          coordinates: [
            [8, 49],
            [8.1, 49.1],
          ],
        },
        properties: feature(42).properties,
        style: {},
        categories: [],
      },
    ];
    const r = mounted(data);
    const fn = (
      r as unknown as { vectorLayer: { getStyleFunction(): StyleFn } }
    ).vectorLayer.getStyleFunction();
    const features = (
      r as unknown as { source: { getFeatures(): Feature[] } }
    ).source.getFeatures();
    const stroke = (i: number) => fn(features[i]!, 1).getStroke();
    expect([stroke(0).getWidth(), stroke(0).getLineDash(), stroke(0).getColor()]).toEqual([
      12,
      [2, 8],
      'rgba(255, 0, 0, 0.5)',
    ]);
    expect(stroke(1).getWidth()).toBe(4);
    expect(stroke(2).getWidth()).toBe(2);
  });

  it('applies the opacity to markers and hides filtered features', () => {
    const { style } = styleOf({ opacity: 0.4 }, {}, 'Point');
    expect(style.getImage().getOpacity()).toBe(0.4);
  });
});

describe('EditableOpenLayersRenderer', () => {
  function editor(): EditableOpenLayersRenderer {
    const r = new EditableOpenLayersRenderer();
    r.mount(target, { data: makeData() });
    return r;
  }
  type Internals = { draw: Draw | null; modify: Modify; source: { getFeatures(): Feature[] } };

  it('switches tools', () => {
    const r = editor();
    const inner = r as unknown as Internals;
    expect(r.getTool()).toBe('select');
    expect(inner.modify.getActive()).toBe(true);
    for (const tool of ['point', 'line', 'polygon'] as const) {
      r.setTool(tool);
      expect(r.getTool()).toBe(tool);
      expect(inner.draw).not.toBeNull();
      expect(inner.modify.getActive()).toBe(false);
    }
    r.setTool('select');
    expect(inner.draw).toBeNull();
    expect(inner.modify.getActive()).toBe(true);
  });

  it('reports drawn geometries as GeoJSON in WGS84', () => {
    const r = editor();
    const drawn: Array<{ type: string; coordinates: unknown }> = [];
    r.onEdit('draw', (e) => drawn.push(e.geometry as never));
    r.setTool('point');
    const feature = new Feature({ geometry: new Point(fromLonLat([8.41, 49.01])) });
    (r as unknown as Internals).draw?.dispatchEvent(new DrawEvent('drawend', feature));
    expect(drawn).toHaveLength(1);
    expect(drawn[0]?.type).toBe('Point');
    expect((drawn[0]?.coordinates as number[])[0]).toBeCloseTo(8.41, 6);
  });

  it('reports moved features with their id', () => {
    const r = editor();
    const moved: Array<{ id: number; geometry: { coordinates: unknown } }> = [];
    r.onEdit('geometrychange', (e) => moved.push(e as never));
    const inner = r as unknown as Internals;
    const target1 = inner.source.getFeatures()[0]!;
    target1.setGeometry(new Point(fromLonLat([9, 50])));
    const collection = new Collection([target1]);
    inner.modify.dispatchEvent(
      new ModifyEvent(
        'modifyend',
        collection,
        new MapBrowserEvent('pointerup', {} as never, new Event('pointerup') as never),
      ),
    );
    expect(moved).toHaveLength(1);
    expect(moved[0]?.id).toBe(1);
    expect((moved[0]?.geometry.coordinates as number[])[0]).toBeCloseTo(9, 6);
  });

  it('keeps polygon holes and multi geometries through a modification and a round trip', () => {
    const polygon = {
      type: 'Polygon' as const,
      coordinates: [
        [
          [8, 49],
          [9, 49],
          [9, 50],
          [8, 50],
          [8, 49],
        ],
        [
          [8.2, 49.2],
          [8.4, 49.2],
          [8.4, 49.4],
          [8.2, 49.2],
        ],
      ],
    };
    const multiLine = {
      type: 'MultiLineString' as const,
      coordinates: [
        [
          [8, 49],
          [8.5, 49.5],
        ],
        [
          [9, 49],
          [9.5, 49.5],
        ],
      ],
    };
    const multiPolygon = {
      type: 'MultiPolygon' as const,
      coordinates: [
        [
          [
            [8, 49],
            [9, 49],
            [9, 50],
            [8, 49],
          ],
        ],
        [polygon.coordinates[0]!, polygon.coordinates[1]!],
      ],
    };
    const data = makeData();
    const props = feature(1).properties;
    data.layers[0]!.features = [
      { id: 30, geometry: polygon, properties: props, style: {} },
      { id: 31, geometry: multiLine, properties: props, style: {} },
      { id: 32, geometry: multiPolygon, properties: props, style: {} },
    ];
    const r = new EditableOpenLayersRenderer();
    r.mount(target, { data });
    const moved: Array<{ id: number; geometry: { type: string; coordinates: unknown } }> = [];
    r.onEdit('geometrychange', (e) => moved.push(e as never));
    const inner = r as unknown as Internals;
    const features = inner.source.getFeatures();
    inner.modify.dispatchEvent(
      new ModifyEvent(
        'modifyend',
        new Collection(features),
        new MapBrowserEvent('pointerup', {} as never, new Event('pointerup') as never),
      ),
    );
    expect(moved.map((m) => m.id)).toEqual([30, 31, 32]);
    expect(moved[0]!.geometry).toEqual(polygon);
    expect((moved[0]!.geometry.coordinates as unknown[]).length).toBe(2);
    expect(moved[1]!.geometry).toEqual(multiLine);
    expect(moved[2]!.geometry).toEqual(multiPolygon);
  });

  it('keeps drawing and editing working after the map was rebuilt', () => {
    const r = editor();
    r.setTool('line');
    const data = makeData();
    data.settings = { ...data.settings, scale: false };
    r.update(data);
    const inner = r as unknown as Internals;
    expect(r.getTool()).toBe('line');
    expect(inner.draw).not.toBeNull();
    r.setTool('select');
    expect(inner.modify.getActive()).toBe(true);
    const interactions = (
      r as unknown as { map: { getInteractions(): { getArray(): unknown[] } } }
    ).map
      .getInteractions()
      .getArray();
    expect(interactions).toContain(inner.modify);
  });

  it('keeps Snap as the last interaction so drawing snaps to existing geometry', () => {
    const r = editor();
    const inner = r as unknown as Internals & {
      snap: unknown;
      map: { getInteractions(): { getArray(): unknown[] } };
    };
    const last = () => inner.map.getInteractions().getArray().at(-1);
    expect(last()).toBe(inner.snap);
    for (const tool of ['point', 'line', 'polygon', 'select', 'point'] as const) {
      r.setTool(tool);
      expect(last()).toBe(inner.snap);
    }
  });

  it('ignores map clicks while a draw tool is active', () => {
    const r = editor();
    const events: Array<number | null> = [];
    r.on('select', (id) => events.push(id));
    r.setTool('point');
    (r as unknown as { onMapClick(e: unknown): void }).onMapClick({ pixel: [0, 0] });
    expect(events).toEqual([]);
    r.selectFeature(1);
    expect(events).toEqual([1]);
  });

  it('unsubscribes edit handlers and cleans up on destroy', () => {
    const r = editor();
    const handler = vi.fn();
    const off = r.onEdit('draw', handler);
    off();
    r.destroy();
    expect(() => r.setTool('point')).not.toThrow();
  });
});

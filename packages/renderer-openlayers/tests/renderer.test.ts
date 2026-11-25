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

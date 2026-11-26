import OlMap from 'ol/Map.js';
import View from 'ol/View.js';
import Feature from 'ol/Feature.js';
import Overlay from 'ol/Overlay.js';
import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';
import VectorSource from 'ol/source/Vector.js';
import ClusterSource from 'ol/source/Cluster.js';
import XYZ from 'ol/source/XYZ.js';
import ScaleLine from 'ol/control/ScaleLine.js';
import Zoom from 'ol/control/Zoom.js';
import DragPan from 'ol/interaction/DragPan.js';
import { defaults as defaultInteractions } from 'ol/interaction/defaults.js';
import { createEmpty, extend } from 'ol/extent.js';
import { fromLonLat, toLonLat, transformExtent } from 'ol/proj.js';
import type MapBrowserEvent from 'ol/MapBrowserEvent.js';
import type { Coordinate } from 'ol/coordinate.js';
import type Point from 'ol/geom/Point.js';
import type { StyleFunction } from 'ol/style/Style.js';
import { boundsOf, flattenPositions } from '@sw-maps/map-contracts';
import type {
  BoundsTuple,
  MapRenderData,
  MapRenderer,
  RendererEventMap,
  RendererOptions,
  RendererView,
  Unsubscribe,
} from '@sw-maps/map-contracts';
import { buildAttribution } from './attribution';
import { Emitter } from './emitter';
import { toOlGeometry } from './geometry';
import { buildPopup } from './popup';
import type { FeatureMeta } from './styles';
import { createPmtilesSource } from './pmtiles';
import { ruleStyle } from './styles';
import { createClusterStyleFunction, createStyleFunction, layerInRange } from './styles';

export interface RendererConfig {
  /** Viewer: popups on selection. Editor: selection is shown by the properties panel instead. */
  popups: boolean;
}

const FEATURE_ID = 'swmapId';
const AUTO_BOUNDS_MAX_ZOOM = 16;
const CLUSTER_DISTANCE = 40;

export class OpenLayersRenderer implements MapRenderer {
  protected map: OlMap | null = null;
  protected data: MapRenderData | null = null;
  protected readonly source = new VectorSource();
  protected vectorLayer: VectorLayer | null = null;
  private clusterLayer: VectorLayer | null = null;
  private clusterSource: ClusterSource | null = null;
  private visibleIds: Set<number> | null = null;
  protected tileLayer: TileLayer<XYZ> | null = null;
  protected readonly events = new Emitter<RendererEventMap>();
  protected selectedId: number | null = null;
  private target: HTMLElement | null = null;
  private canvas: HTMLElement | null = null;
  private attribution: HTMLElement | null = null;
  private fullscreenButton: HTMLButtonElement | null = null;
  private overlay: Overlay | null = null;
  private popupFor: number | null = null;
  private readonly metas = new Map<number, FeatureMeta>();
  private readonly signatures = new Map<number, string>();
  private tileErrors = 0;
  private cleanup: Array<() => void> = [];
  private settingsKey = '';

  constructor(protected readonly config: RendererConfig = { popups: true }) {}

  mount(target: HTMLElement, options: RendererOptions): void {
    if (this.map) this.destroy();
    this.target = target;
    this.data = options.data;
    this.canvas = target.ownerDocument.createElement('div');
    this.canvas.className = 'swmap__canvas';
    target.append(this.canvas);
    this.build();
  }

  update(data: MapRenderData): void {
    if (!this.map || !this.target) throw new Error('Renderer is not mounted.');
    const previous = this.data;
    this.data = data;
    const key = this.computeSettingsKey(data);
    if (previous === null || key !== this.settingsKey) {
      // Interaction/control settings changed: rebuild the map around the current view.
      const view = this.getView();
      this.teardownMap();
      this.build(view);
      return;
    }
    this.applyView(data);
    this.applyProvider(data);
    this.syncFeatures();
  }

  setView(view: Partial<RendererView>): void {
    const olView = this.requireMap().getView();
    if (view.center) olView.setCenter(fromLonLat(view.center));
    if (view.zoom !== undefined) olView.setZoom(view.zoom);
  }

  getView(): RendererView {
    const view = this.requireMap().getView();
    const center = view.getCenter() ?? [0, 0];
    const [lon, lat] = toLonLat(center);
    return { center: [lon ?? 0, lat ?? 0], zoom: view.getZoom() ?? 0 };
  }

  /**
   * Visible area in WGS84 for loading features. Longitudes are normalised into [-180, 180]; when the view crosses
   * the antimeridian (or shows another world copy) minLon is greater than maxLon, which the server treats as two
   * ranges. A view of a whole world or more covers every longitude.
   */
  getBounds(): BoundsTuple {
    const map = this.requireMap();
    const extent = map.getView().calculateExtent(map.getSize());
    const [west = -180, south = -85, east = 180, north = 85] = transformExtent(
      extent,
      'EPSG:3857',
      'EPSG:4326',
    );
    const clamp = (v: number, lo: number, hi: number): number => Math.min(hi, Math.max(lo, v));
    const lat: [number, number] = [clamp(south, -90, 90), clamp(north, -90, 90)];
    if (east - west >= 360) return [-180, lat[0], 180, lat[1]];
    const wrap = (lon: number): number => ((((lon + 180) % 360) + 360) % 360) - 180;
    return [wrap(west), lat[0], wrap(east), lat[1]];
  }

  fitBounds(bounds: BoundsTuple, options: { padding?: number; maxZoom?: number } = {}): void {
    const pad = options.padding ?? 32;
    this.requireMap()
      .getView()
      .fit(transformExtent(bounds, 'EPSG:4326', 'EPSG:3857'), {
        padding: [pad, pad, pad, pad],
        maxZoom: options.maxZoom ?? AUTO_BOUNDS_MAX_ZOOM,
        duration: this.duration(),
      });
  }

  on<K extends keyof RendererEventMap>(
    event: K,
    handler: (payload: RendererEventMap[K]) => void,
  ): Unsubscribe {
    return this.events.on(event, handler);
  }

  /** Selects a feature (list synchronisation): centres it and opens the popup. Pass null to clear. */
  selectFeature(id: number | null, options: { centre?: boolean } = {}): void {
    if (id !== null && !this.metas.has(id)) id = null;
    this.selectedId = id;
    this.vectorLayer?.changed();
    this.clusterLayer?.changed();
    if (id === null) {
      this.closePopup();
    } else {
      if (options.centre) this.centreOn(id);
      if (this.config.popups) this.openPopup(id);
    }
    this.events.emit('select', id);
  }

  destroy(): void {
    this.teardownMap();
    this.canvas?.remove();
    this.canvas = null;
    this.target = null;
    this.data = null;
    this.events.clear();
  }

  /** Whether more than a handful of tiles failed to load (used for a user visible message). */
  get tileErrorCount(): number {
    return this.tileErrors;
  }

  // ---------------------------------------------------------------------------------------------------------------

  protected requireMap(): OlMap {
    if (!this.map) throw new Error('Renderer is not mounted.');
    return this.map;
  }

  /** Zoom of a resolution, used for the per-layer zoom limits. */
  protected zoomOf = (resolution: number): number | null =>
    this.map?.getView().getZoomForResolution(resolution) ?? null;

  protected isVisible = (id: number): boolean =>
    this.visibleIds === null || this.visibleIds.has(id);

  /** Shows only the given features (search/filter of the viewer); null shows all. Hidden features are deselected. */
  setVisibleIds(ids: Iterable<number> | null): void {
    this.visibleIds = ids === null ? null : new Set(ids);
    if (this.selectedId !== null && !this.isVisible(this.selectedId)) this.selectFeature(null);
    this.clusterSource?.refresh();
    this.vectorLayer?.changed();
    this.clusterLayer?.changed();
  }

  protected metaOf = (feature: unknown): FeatureMeta | undefined => {
    const id = (feature as Feature).get(FEATURE_ID) as number | undefined;
    return id === undefined ? undefined : this.metas.get(id);
  };

  protected duration(): number {
    const win = this.target?.ownerDocument.defaultView;
    return win?.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : 250;
  }

  protected onMapClick(event: MapBrowserEvent): void {
    const map = this.requireMap();
    const hit = map.forEachFeatureAtPixel(event.pixel, (f) => f as Feature, { hitTolerance: 8 });
    const members = hit?.get('features') as Feature[] | undefined;
    if (members && members.length > 1) {
      this.expandCluster(members);
      return;
    }
    if (members) {
      this.selectFeature((members[0]?.get(FEATURE_ID) as number | undefined) ?? null);
      return;
    }
    const id = hit ? ((hit.get(FEATURE_ID) as number | undefined) ?? null) : null;
    this.selectFeature(id);
  }

  private build(initialView?: RendererView): void {
    const data = this.data;
    const canvas = this.canvas;
    const target = this.target;
    if (!data || !canvas || !target) throw new Error('Renderer is not mounted.');
    const doc = target.ownerDocument;
    const { viewport, settings, provider, strings } = data;
    this.settingsKey = this.computeSettingsKey(data);
    this.tileErrors = 0;

    const touchPan = new DragPan({
      condition: (event) => {
        const pointerType = (event.originalEvent as PointerEvent | undefined)?.pointerType;
        return pointerType === 'touch' ? settings.touch : settings.drag;
      },
    });
    const interactions = defaultInteractions({
      dragPan: false,
      mouseWheelZoom: settings.scrollZoom,
      pinchZoom: settings.touch,
      doubleClickZoom: settings.drag,
      altShiftDragRotate: false,
      pinchRotate: false,
    }).extend([touchPan]);

    const view = new View({
      center: fromLonLat(initialView?.center ?? viewport.center),
      zoom: initialView?.zoom ?? viewport.zoom,
      minZoom: viewport.minZoom,
      maxZoom: viewport.maxZoom,
      extent: viewport.bounds
        ? transformExtent(viewport.bounds, 'EPSG:4326', 'EPSG:3857')
        : undefined,
      constrainOnlyCenter: true,
    });

    const controls = [];
    if (settings.zoomControls) {
      controls.push(new Zoom({ zoomInTipLabel: strings.zoomIn, zoomOutTipLabel: strings.zoomOut }));
    }
    if (settings.scale) controls.push(new ScaleLine({ units: 'metric' }));

    this.tileLayer = new TileLayer({
      source: this.createTileSource(data) ?? undefined,
      visible: provider !== null,
    });
    const featureStyle = createStyleFunction(
      this.metaOf,
      () => this.selectedId,
      this.isVisible,
      this.zoomOf,
    );
    this.vectorLayer = new VectorLayer({
      source: this.source,
      style: settings.cluster
        ? (feature, resolution) =>
            feature.getGeometry()?.getType() === 'Point'
              ? undefined
              : (featureStyle as StyleFunction)(feature, resolution)
        : featureStyle,
    });
    const layers: Array<TileLayer<XYZ> | VectorLayer> = [this.tileLayer, this.vectorLayer];
    if (settings.cluster) {
      this.clusterSource = new ClusterSource({
        source: this.source,
        distance: CLUSTER_DISTANCE,
        geometryFunction: (feature) => {
          const geometry = feature.getGeometry();
          const id = feature.get(FEATURE_ID) as number;
          const meta = this.metas.get(id);
          const inRange = meta
            ? layerInRange(meta.layer.style, this.map?.getView().getZoom() ?? null)
            : true;
          return geometry?.getType() === 'Point' && this.isVisible(id) && inRange
            ? (geometry as Point)
            : null;
        },
      });
      this.clusterLayer = new VectorLayer({
        source: this.clusterSource,
        style: createClusterStyleFunction(featureStyle),
      });
      layers.push(this.clusterLayer);
    }
    this.map = new OlMap({
      target: canvas,
      layers,
      view,
      controls,
      interactions,
    });

    this.attachTileErrorHandling();
    if (provider) {
      this.attribution = buildAttribution(provider.attribution, strings.attributionLabel, doc);
      target.append(this.attribution);
    }
    if (settings.fullscreen && doc.fullscreenEnabled) this.addFullscreenButton(target, doc);
    if (this.config.popups) {
      this.overlay = this.createOverlay(doc);
      this.createTooltip(doc);
    }

    this.map.on('singleclick', (event) => this.onMapClick(event));
    let lastZoom = Math.round((this.map.getView().getZoom() ?? 0) * 2);
    this.map.on('moveend', () => {
      this.events.emit('viewchange', this.getView());
      // Layers with zoom limits change the clustered point set when the zoom changes.
      const zoom = Math.round((this.map?.getView().getZoom() ?? 0) * 2);
      if (zoom !== lastZoom && this.hasZoomLimits()) this.clusterSource?.refresh();
      lastZoom = zoom;
    });

    this.signatures.clear();
    this.syncFeatures();
    if (!initialView) this.fitToFeaturesIfAuto();
    this.selectedId = null;
    this.afterBuild();
  }

  /** Called after every (re)build of the OpenLayers map; subclasses attach their interactions here. */
  protected afterBuild(): void {}

  private teardownMap(): void {
    for (const fn of this.cleanup) fn();
    this.cleanup = [];
    this.closePopup();
    this.overlay = null;
    this.attribution?.remove();
    this.attribution = null;
    this.fullscreenButton?.remove();
    this.fullscreenButton = null;
    this.source.clear();
    this.metas.clear();
    this.signatures.clear();
    if (this.map) {
      this.map.setTarget(undefined);
      this.map.dispose();
      this.map = null;
    }
    this.clusterSource?.dispose();
    this.clusterSource = null;
    this.clusterLayer = null;
    this.vectorLayer = null;
    this.tileLayer = null;
  }

  private hasZoomLimits(): boolean {
    return (this.data?.layers ?? []).some(
      (l) => typeof l.style['minZoom'] === 'number' || typeof l.style['maxZoom'] === 'number',
    );
  }

  private computeSettingsKey(data: MapRenderData): string {
    const s = data.settings;
    const p = data.provider;
    // The tile grid (size, zoom range) is fixed when the source is created: changing it needs a rebuild.
    return JSON.stringify([
      p?.type,
      p?.type === 'pmtiles' ? p.urlTemplate : null,
      p?.tileSize,
      p?.minZoom,
      p?.maxZoom,
      s.zoomControls,
      s.scale,
      s.fullscreen,
      s.scrollZoom,
      s.touch,
      s.drag,
      s.cluster,
      data.provider === null,
    ]);
  }

  private createTileSource(data: MapRenderData): XYZ | null {
    const p = data.provider;
    if (!p) return null;
    if (p.type === 'pmtiles') {
      return createPmtilesSource(p, () =>
        this.events.emit('error', new Error('Vector tiles are not supported.')),
      );
    }
    return new XYZ({
      url: p.urlTemplate,
      minZoom: p.minZoom,
      maxZoom: p.maxZoom,
      tileSize: p.tileSize,
      // Attributions are rendered by the plugin itself (always visible, also in fullscreen).
      attributions: [],
      crossOrigin: 'anonymous',
    });
  }

  private applyProvider(data: MapRenderData): void {
    const source = this.tileLayer?.getSource();
    const p = data.provider;
    if (!p || !source) return;
    if (p.type === 'xyz' && source.getUrls()?.[0] !== p.urlTemplate) source.setUrl(p.urlTemplate);
    if (this.attribution) {
      const next = buildAttribution(
        p.attribution,
        data.strings.attributionLabel,
        this.target?.ownerDocument,
      );
      this.attribution.replaceWith(next);
      this.attribution = next;
    }
  }

  private applyView(data: MapRenderData): void {
    const view = this.requireMap().getView();
    view.setMinZoom(data.viewport.minZoom);
    view.setMaxZoom(data.viewport.maxZoom);
  }

  private attachTileErrorHandling(): void {
    const source = this.tileLayer?.getSource();
    if (!source) return;
    const onError = (): void => {
      this.tileErrors += 1;
      if (this.tileErrors === 4)
        this.events.emit('error', new Error('Map tiles could not be loaded.'));
    };
    source.on('tileloaderror', onError);
    this.cleanup.push(() => source.un('tileloaderror', onError));
  }

  private addFullscreenButton(target: HTMLElement, doc: Document): void {
    const strings = this.data?.strings;
    const button = doc.createElement('button');
    button.type = 'button';
    button.className = 'swmap__fullscreen';
    const sync = (): void => {
      const active = doc.fullscreenElement === target;
      const label = active ? (strings?.exitFullscreen ?? '') : (strings?.fullscreen ?? '');
      button.setAttribute('aria-label', label);
      button.title = label;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      target.classList.toggle('is-fullscreen', active);
      this.map?.updateSize();
    };
    button.addEventListener('click', () => {
      if (doc.fullscreenElement === target) void doc.exitFullscreen();
      else void target.requestFullscreen?.();
    });
    doc.addEventListener('fullscreenchange', sync);
    this.cleanup.push(() => doc.removeEventListener('fullscreenchange', sync));
    sync();
    target.append(button);
    this.fullscreenButton = button;
  }

  private createOverlay(doc: Document): Overlay {
    const element = doc.createElement('div');
    element.className = 'swmap-overlay';
    const overlay = new Overlay({
      element,
      positioning: 'bottom-center',
      offset: [0, -40],
      stopEvent: true,
      autoPan: { animation: { duration: this.duration() } },
    });
    this.requireMap().addOverlay(overlay);
    return overlay;
  }

  /** Hover label with the title of a location (pointer devices; the popup stays the full detail view). */
  private createTooltip(doc: Document): Overlay {
    const element = doc.createElement('div');
    element.className = 'swmap-tooltip';
    element.setAttribute('role', 'tooltip');
    const overlay = new Overlay({
      element,
      positioning: 'bottom-center',
      offset: [0, -44],
      stopEvent: false,
    });
    const map = this.requireMap();
    map.addOverlay(overlay);
    map.on('pointermove', (event) => {
      if (event.dragging) return;
      const hit = map.forEachFeatureAtPixel(event.pixel, (f) => f as Feature, { hitTolerance: 6 });
      const members = hit?.get('features') as Feature[] | undefined;
      const id = members
        ? members.length === 1
          ? (members[0]?.get(FEATURE_ID) as number | undefined)
          : undefined
        : (hit?.get(FEATURE_ID) as number | undefined);
      const title =
        id === undefined || id === this.popupFor ? '' : this.metas.get(id)?.properties.title;
      const target = map.getTargetElement();
      if (target) target.style.cursor = hit ? 'pointer' : '';
      if (!title) {
        overlay.setPosition(undefined);
        return;
      }
      element.textContent = title;
      overlay.setPosition(event.coordinate);
    });
    return overlay;
  }

  private openPopup(id: number): void {
    const meta = this.metas.get(id);
    const feature = this.source.getFeatures().find((f) => f.get(FEATURE_ID) === id);
    const geometry = feature?.getGeometry();
    if (!meta || !geometry || !this.overlay || !this.data) return;
    const element = this.overlay.getElement();
    if (!element) return;
    const doc = element.ownerDocument;
    const popup = buildPopup(
      meta.properties,
      this.data.strings,
      () => this.selectFeature(null),
      doc,
    );
    element.replaceChildren(popup);
    const position = this.anchorOf(geometry.getExtent(), geometry.getType() === 'Point');
    this.overlay.setPosition(position);
    this.popupFor = id;
  }

  private closePopup(): void {
    this.overlay?.setPosition(undefined);
    this.overlay?.getElement()?.replaceChildren();
    this.popupFor = null;
  }

  private anchorOf(extent: number[], isPoint: boolean): Coordinate {
    const [minX = 0, minY = 0, maxX = 0, maxY = 0] = extent;
    return isPoint ? [minX, minY] : [(minX + maxX) / 2, maxY];
  }

  private centreOn(id: number): void {
    const feature = this.source.getFeatures().find((f) => f.get(FEATURE_ID) === id);
    const geometry = feature?.getGeometry();
    if (!geometry) return;
    const view = this.requireMap().getView();
    view.animate({
      center: this.anchorOf(geometry.getExtent(), geometry.getType() === 'Point'),
      duration: this.duration(),
    });
  }

  /** A click on a group zooms to its members; at the maximum zoom coincident markers stay grouped (use the list). */
  private expandCluster(members: Feature[]): void {
    const extent = createEmpty();
    for (const member of members) {
      const geometry = member.getGeometry();
      if (geometry) extend(extent, geometry.getExtent());
    }
    const view = this.requireMap().getView();
    view.fit(extent, {
      padding: [64, 64, 64, 64],
      maxZoom: view.getMaxZoom(),
      duration: this.duration(),
    });
  }

  private fitToFeaturesIfAuto(): void {
    const data = this.data;
    if (!data?.viewport.autoBounds) return;
    const bounds = boundsOf(
      data.layers.flatMap((l) =>
        l.features.flatMap((f) => flattenPositions(f.geometry.coordinates)),
      ),
    );
    if (bounds === null) return;
    const [minLon, minLat, maxLon, maxLat] = bounds;
    const single = minLon === maxLon && minLat === maxLat;
    this.requireMap()
      .getView()
      .fit(transformExtent(bounds, 'EPSG:4326', 'EPSG:3857'), {
        padding: [48, 48, 48, 48],
        maxZoom: single ? 14 : AUTO_BOUNDS_MAX_ZOOM,
        duration: 0,
      });
  }

  /** Adds, replaces and removes OpenLayers features so that unchanged features (and editor state) stay untouched. */
  protected syncFeatures(): void {
    const data = this.data;
    if (!data) return;
    const wanted = new Map<number, { meta: FeatureMeta; signature: string; geometry: unknown }>();
    const categories = new Map((data.categories ?? []).map((c) => [c.id, c.style]));
    for (const [order, layer] of data.layers.entries()) {
      for (const f of layer.features) {
        const categoryStyle = {
          ...(categories.get(f.categories?.[0] ?? -1) ?? {}),
          ...ruleStyle(layer.style, f.categories ?? []),
        };
        wanted.set(f.id, {
          meta: { id: f.id, layer, order, properties: f.properties, style: f.style, categoryStyle },
          signature: JSON.stringify([
            f.geometry,
            f.properties,
            f.style,
            layer.style,
            categoryStyle,
            order,
          ]),
          geometry: f.geometry,
        });
      }
    }
    for (const existing of this.source.getFeatures()) {
      const id = existing.get(FEATURE_ID) as number;
      const next = wanted.get(id);
      if (!next || this.signatures.get(id) !== next.signature) {
        this.source.removeFeature(existing);
        this.signatures.delete(id);
        this.metas.delete(id);
      }
    }
    for (const [id, item] of wanted) {
      if (this.signatures.has(id)) continue;
      const geometry = toOlGeometry(item.geometry as Parameters<typeof toOlGeometry>[0]);
      if (!geometry) continue;
      const feature = new Feature({ geometry });
      feature.set(FEATURE_ID, id);
      this.source.addFeature(feature);
      this.signatures.set(id, item.signature);
      this.metas.set(id, item.meta);
    }
    for (const [id, item] of wanted) {
      if (this.metas.has(id)) this.metas.set(id, item.meta);
    }
    if (this.selectedId !== null && !this.metas.has(this.selectedId)) this.selectFeature(null);
    else if (this.popupFor !== null) this.openPopup(this.popupFor);
    this.vectorLayer?.changed();
    this.clusterLayer?.changed();
  }
}

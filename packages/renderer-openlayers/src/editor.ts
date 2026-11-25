import Draw from 'ol/interaction/Draw.js';
import Modify from 'ol/interaction/Modify.js';
import Snap from 'ol/interaction/Snap.js';
import type { Type as GeometryTypeName } from 'ol/geom/Geometry.js';
import type Feature from 'ol/Feature.js';
import type {
  EditableRenderer,
  EditorEventMap,
  EditTool,
  Unsubscribe,
} from '@sw-maps/map-contracts';
import { Emitter } from './emitter';
import { fromOlFeature } from './geometry';
import { OpenLayersRenderer } from './renderer';

const DRAW_TYPES: Record<Exclude<EditTool, 'select'>, GeometryTypeName> = {
  point: 'Point',
  line: 'LineString',
  polygon: 'Polygon',
};

/**
 * Editor flavour of the renderer: Draw (marker, line, polygon) and Modify (move markers, change vertices).
 * Persisted state is owned by the admin store; the editor only reports geometry changes.
 */
export class EditableOpenLayersRenderer extends OpenLayersRenderer implements EditableRenderer {
  private readonly editEvents = new Emitter<EditorEventMap>();
  private tool: EditTool = 'select';
  private draw: Draw | null = null;
  private modify: Modify | null = null;
  private snap: Snap | null = null;

  constructor() {
    super({ popups: false });
  }

  override mount(target: HTMLElement, options: Parameters<OpenLayersRenderer['mount']>[1]): void {
    super.mount(target, options);
    this.modify = new Modify({ source: this.source });
    this.modify.on('modifyend', (event) => {
      for (const feature of event.features.getArray() as Feature[]) {
        const id = feature.get('swmapId') as number | undefined;
        const geometry = fromOlFeature(feature);
        if (id !== undefined && geometry) this.editEvents.emit('geometrychange', { id, geometry });
      }
    });
    this.snap = new Snap({ source: this.source });
    this.requireMap().addInteraction(this.modify);
    this.requireMap().addInteraction(this.snap);
    this.setTool(this.tool);
  }

  setTool(tool: EditTool): void {
    this.tool = tool;
    const map = this.map;
    if (!map) return;
    if (this.draw) {
      map.removeInteraction(this.draw);
      this.draw = null;
    }
    this.modify?.setActive(tool === 'select');
    if (tool === 'select') return;
    const draw = new Draw({ source: undefined, type: DRAW_TYPES[tool] });
    draw.on('drawend', (event) => {
      const geometry = fromOlFeature(event.feature);
      if (geometry) this.editEvents.emit('draw', { geometry });
    });
    map.addInteraction(draw);
    this.draw = draw;
  }

  getTool(): EditTool {
    return this.tool;
  }

  onEdit<K extends keyof EditorEventMap>(
    event: K,
    handler: (payload: EditorEventMap[K]) => void,
  ): Unsubscribe {
    return this.editEvents.on(event, handler);
  }

  protected override onMapClick(event: Parameters<OpenLayersRenderer['onMapClick']>[0]): void {
    if (this.tool === 'select') super.onMapClick(event);
  }

  override destroy(): void {
    this.editEvents.clear();
    this.draw = null;
    this.modify = null;
    this.snap = null;
    super.destroy();
  }
}

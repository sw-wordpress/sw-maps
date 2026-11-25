<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { EditableOpenLayersRenderer } from '@sw-maps/renderer-openlayers';
import type {
  BoundsTuple,
  EditTool,
  Geometry,
  MapRenderData,
  RendererView,
} from '@sw-maps/map-contracts';

const props = defineProps<{
  data: MapRenderData | null;
  tool: EditTool;
  selectedId: number | null;
  mobile: boolean;
}>();
const emit = defineEmits<{
  draw: [geometry: Geometry];
  geometrychange: [id: number, geometry: Geometry];
  select: [id: number | null];
}>();
const { t } = useI18n();
const host = ref<HTMLElement | null>(null);
const failed = ref(false);
let renderer: EditableOpenLayersRenderer | null = null;

function start(): void {
  if (!host.value || !props.data || renderer) return;
  try {
    const instance = new EditableOpenLayersRenderer();
    instance.mount(host.value, { data: props.data });
    instance.onEdit('draw', ({ geometry }) => emit('draw', geometry));
    instance.onEdit('geometrychange', ({ id, geometry }) => emit('geometrychange', id, geometry));
    instance.on('select', (id) => emit('select', id));
    instance.setTool(props.tool);
    renderer = instance;
    lastApplied = signature(props.data.viewport);
  } catch (error) {
    console.error('sw-maps: editor map failed', error);
    failed.value = true;
  }
}

onMounted(start);
onBeforeUnmount(() => {
  renderer?.destroy();
  renderer = null;
});

watch(
  () => props.data,
  (data) => {
    if (!data) return;
    if (!renderer) {
      start();
      return;
    }
    renderer.update(data);
    // Applies a start view that was typed into the form (not one caused by panning).
    const view = renderer.getView();
    const wanted = data.viewport;
    const moved =
      Math.abs(view.center[0] - wanted.center[0]) > 1e-6 ||
      Math.abs(view.center[1] - wanted.center[1]) > 1e-6 ||
      Math.abs(view.zoom - wanted.zoom) > 0.01;
    if (moved && lastApplied !== signature(wanted))
      renderer.setView({ center: wanted.center, zoom: wanted.zoom });
    lastApplied = signature(wanted);
  },
);
watch(
  () => props.tool,
  (tool) => renderer?.setTool(tool),
);
watch(
  () => props.selectedId,
  (id) => renderer?.selectFeature(id),
);

let lastApplied = '';
const signature = (v: MapRenderData['viewport']): string => `${v.center.join(',')}|${v.zoom}`;

defineExpose({
  getView: (): RendererView | null => renderer?.getView() ?? null,
  fitBounds: (bounds: BoundsTuple): void => renderer?.fitBounds(bounds),
});
</script>

<template>
  <div
    ref="host"
    class="swmap-editor__canvas"
    :class="{ 'swmap-editor__canvas--mobile': mobile }"
    role="region"
    :aria-label="t('editor.mapCanvas')"
  >
    <p v-if="failed" class="q-pa-md text-negative" role="alert">{{ t('viewer.error') }}</p>
  </div>
</template>

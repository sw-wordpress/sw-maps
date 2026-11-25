<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Geometry } from '@sw-maps/map-contracts';
import { boundsOf, flattenPositions } from '@sw-maps/map-contracts';
import { useNotify } from '@/composables/useNotify';
import { setUnsavedMessage } from '@/router';
import { useEditorStore } from '@/stores/editor';
import { errorMessage } from '@/utils/errors';
import { viewerStrings } from '@/utils/viewerStrings';
import EditorCanvas from './EditorCanvas.vue';
import EditorToolbar from './EditorToolbar.vue';
import FeatureForm from './FeatureForm.vue';
import LayerPanel from './LayerPanel.vue';
import MapSettingsForm from './MapSettingsForm.vue';

const props = defineProps<{ id: string }>();
const { t } = useI18n();
const store = useEditorStore();
const notify = useNotify();
const mobile = ref(false);
const tab = ref<'map' | 'feature'>('map');
const canvas = ref<InstanceType<typeof EditorCanvas> | null>(null);
const failed = ref(false);

const renderData = computed(() => store.renderData(viewerStrings(t)));

async function load(): Promise<void> {
  failed.value = false;
  try {
    await store.load(Number(props.id));
  } catch (error) {
    failed.value = true;
    notify.error(errorMessage(error, t('editor.loadFailed')));
  }
}

function beforeUnload(event: BeforeUnloadEvent): void {
  if (store.dirty) {
    event.preventDefault();
    event.returnValue = '';
  }
}

onMounted(() => {
  setUnsavedMessage(t('editor.unsavedConfirm'));
  window.addEventListener('beforeunload', beforeUnload);
  void load();
});
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
watch(
  () => props.id,
  () => void load(),
);
watch(
  () => store.selectedFeatureId,
  (id) => {
    if (id !== null) tab.value = 'feature';
  },
);

async function save(): Promise<void> {
  if (await store.save()) notify.success(t('editor.saved'));
  else notify.error(errorMessage(store.saveError, t('app.saveFailed')));
}

function onDraw(geometry: Geometry): void {
  store.addGeometry(geometry);
  store.tool = 'select';
}

function useCurrentView(): void {
  const view = canvas.value?.getView();
  if (view)
    store.patchMap({ viewport: { center: view.center, zoom: Math.round(view.zoom * 100) / 100 } });
}

function fitAll(): void {
  const bounds = boundsOf(
    (store.doc?.features ?? []).flatMap((f) => flattenPositions(f.geometry.coordinates)),
  );
  if (bounds) canvas.value?.fitBounds(bounds);
}
</script>

<template>
  <div v-if="store.doc">
    <div class="row items-center q-mb-sm">
      <q-btn flat dense no-caps icon="r_arrow_back" :label="t('editor.back')" to="/maps" />
      <h2 class="text-h6 q-my-none q-ml-md">{{ store.doc.map.title }}</h2>
    </div>
    <EditorToolbar
      v-model:tool="store.tool"
      v-model:mobile="mobile"
      :can-undo="store.canUndo"
      :can-redo="store.canRedo"
      :dirty="store.dirty"
      :saving="store.saving"
      @undo="store.undo()"
      @redo="store.redo()"
      @save="save"
      @fit="fitAll"
    />
    <div class="swmap-editor">
      <LayerPanel
        :layers="store.doc.layers"
        :features="store.doc.features"
        :active-layer-id="store.activeLayerId"
        :selected-id="store.selectedFeatureId"
        @update:active-layer-id="store.activeLayerId = $event"
        @add-layer="store.createLayer(`${t('editor.newLayer')} ${store.doc.layers.length + 1}`)"
        @rename-layer="(id, title) => store.patchLayer(id, { title })"
        @toggle-layer="(id, visible) => store.patchLayer(id, { visible })"
        @delete-layer="store.deleteLayer($event)"
        @select="store.select($event)"
      />
      <EditorCanvas
        ref="canvas"
        :data="renderData"
        :tool="store.tool"
        :selected-id="store.selectedFeatureId"
        :mobile="mobile"
        @draw="onDraw"
        @geometrychange="(id, geometry) => store.moveFeature(id, geometry)"
        @select="store.select($event)"
      />
      <div class="swmap-card swmap-editor__side">
        <q-tabs v-model="tab" dense no-caps align="left">
          <q-tab name="map" :label="t('editor.tabMap')" data-test="tab-map" />
          <q-tab name="feature" :label="t('editor.tabFeature')" data-test="tab-feature" />
        </q-tabs>
        <q-separator class="q-mb-md" />
        <MapSettingsForm
          v-if="tab === 'map'"
          :map="store.doc.map"
          :providers="store.providers"
          @patch="store.patchMap($event)"
          @use-current-view="useCurrentView"
        />
        <template v-else>
          <FeatureForm
            v-if="store.selectedFeature"
            :feature="store.selectedFeature"
            @patch="store.patchFeature(store.selectedFeature.id, $event)"
            @coordinates="
              (lon, lat) => store.setPointCoordinates(store.selectedFeature!.id, lon, lat)
            "
            @delete="store.deleteFeature(store.selectedFeature.id)"
          />
          <p v-else class="swmap-muted" data-test="select-hint">{{ t('editor.selectHint') }}</p>
        </template>
      </div>
    </div>
  </div>
  <div v-else-if="failed" class="swmap-card" role="alert">
    {{ t('editor.loadFailed') }}
    <q-btn flat no-caps :label="t('common.reload')" @click="load" />
  </div>
  <q-spinner v-else color="primary" size="2em" />
</template>

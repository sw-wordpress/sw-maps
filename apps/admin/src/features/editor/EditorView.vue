<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Geometry } from '@sw-maps/map-contracts';
import { boundsOf, flattenPositions } from '@sw-maps/map-contracts';
import { useNotify } from '@/composables/useNotify';
import { getConfig, hasCapability } from '@/config';
import { setUnsavedMessage } from '@/router';
import { useEditorStore } from '@/stores/editor';
import { errorMessage } from '@/utils/errors';
import { viewerStrings } from '@/utils/viewerStrings';
import EditorCanvas from './EditorCanvas.vue';
import EditorToolbar from './EditorToolbar.vue';
import FeatureForm from './FeatureForm.vue';
import LayerPanel from './LayerPanel.vue';
import MapSettingsForm from './MapSettingsForm.vue';
import CategoryDialog from './CategoryDialog.vue';
import ImportDialog from '@/features/imports/ImportDialog.vue';

const props = defineProps<{ id: string }>();
const { t } = useI18n();
const store = useEditorStore();
const notify = useNotify();
const mobile = ref(false);
const tab = ref<'map' | 'feature'>('map');
const canvas = ref<InstanceType<typeof EditorCanvas> | null>(null);
const failed = ref(false);
const importOpen = ref(false);
const categoriesOpen = ref(false);
const canManageCategories = hasCapability(getConfig(), 'swmap_manage_layers');
const canImport = hasCapability(getConfig(), 'swmap_manage_imports');

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

async function onImported(): Promise<void> {
  await load();
  fitAll();
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
import PageHeader from '@/components/ui/PageHeader.vue';
import SwmapCard from '@/components/ui/SwmapCard.vue';
import DataState from '@/components/ui/DataState.vue';
</script>

<template>
  <div v-if="store.doc">
    <PageHeader
      :title="store.doc.map.title || t('maps.untitled')"
      back-to="/maps"
      :back-label="t('editor.back')"
    >
      <q-btn
        v-if="canImport"
        outline
        no-caps
        icon="r_upload_file"
        :label="t('import.open')"
        :disable="store.dirty || store.saving"
        data-test="import"
        @click="importOpen = true"
      >
        <q-tooltip v-if="store.dirty">{{ t('import.saveFirst') }}</q-tooltip>
      </q-btn>
    </PageHeader>
    <CategoryDialog
      v-if="canManageCategories"
      v-model="categoriesOpen"
      :categories="store.categories"
      :save="store.saveCategory"
      :remove="store.removeCategory"
    />
    <ImportDialog
      v-if="canImport"
      v-model="importOpen"
      :map-id="store.doc.map.id"
      :layers="store.doc.layers"
      @imported="onImported"
    />
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
    <div class="swmap-editor" :inert="store.saving">
      <LayerPanel
        :layers="store.doc.layers"
        :features="store.doc.features"
        :active-layer-id="store.activeLayerId"
        :selected-id="store.selectedFeatureId"
        @update:active-layer-id="store.activeLayerId = $event"
        @add-layer="store.createLayer(`${t('editor.newLayer')} ${store.doc.layers.length + 1}`)"
        @rename-layer="(id, title) => store.patchLayer(id, { title })"
        @toggle-layer="(id, visible) => store.patchLayer(id, { visible })"
        @move-layer="(id, direction) => store.reorderLayer(id, direction)"
        @style-layer="(id, style) => store.patchLayer(id, { style })"
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
      <SwmapCard class="swmap-editor__side">
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
            :categories="store.categories"
            :can-manage-categories="canManageCategories"
            @manage-categories="categoriesOpen = true"
            @patch="store.patchFeature(store.selectedFeature.id, $event)"
            @coordinates="
              (lon, lat) => store.setPointCoordinates(store.selectedFeature!.id, lon, lat)
            "
            @delete="store.deleteFeature(store.selectedFeature.id)"
          />
          <p v-else class="swmap-muted" data-test="select-hint">{{ t('editor.selectHint') }}</p>
        </template>
      </SwmapCard>
    </div>
  </div>
  <SwmapCard v-else-if="failed" role="alert">
    {{ t('editor.loadFailed') }}
    <q-btn flat no-caps :label="t('common.reload')" @click="load" />
  </SwmapCard>
  <DataState v-else :loading="true" :error="null" variant="block" />
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FeatureData, LayerData } from '@sw-maps/map-contracts';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

const props = defineProps<{
  layers: LayerData[];
  features: FeatureData[];
  activeLayerId: number | null;
  selectedId: number | null;
}>();
const emit = defineEmits<{
  'update:activeLayerId': [id: number];
  'add-layer': [];
  'rename-layer': [id: number, title: string];
  'toggle-layer': [id: number, visible: boolean];
  'delete-layer': [id: number];
  select: [id: number];
}>();
const { t } = useI18n();
const drafts = reactive<Record<number, string>>({});
const deleting = ref<LayerData | null>(null);
const confirmOpen = ref(false);

const featuresOf = (layerId: number): FeatureData[] =>
  props.features.filter((f) => f.layer_id === layerId);
const label = (f: FeatureData): string => f.properties.title || t('editor.untitledFeature');
const typeLabel = (f: FeatureData): string =>
  t(`feature.type.${f.geometry.type}` as 'feature.type.Point');

/** Typed text is kept as a draft until the field loses focus (one undo step per rename). */
function onRename(layer: LayerData): void {
  const value = (drafts[layer.id] ?? layer.title).trim();
  delete drafts[layer.id];
  if (value !== '' && value !== layer.title) emit('rename-layer', layer.id, value);
}

function askDelete(layer: LayerData): void {
  deleting.value = layer;
  confirmOpen.value = true;
}
</script>

<template>
  <div class="swmap-card swmap-editor__side" data-test="layer-panel">
    <div class="row items-center">
      <div class="text-subtitle1">{{ t('editor.layers') }}</div>
      <q-space />
      <q-btn
        flat
        dense
        round
        icon="add"
        :aria-label="t('editor.addLayer')"
        data-test="add-layer"
        @click="emit('add-layer')"
      />
    </div>
    <p v-if="layers.length === 0" class="swmap-muted">{{ t('editor.noLayers') }}</p>
    <div v-for="layer in layers" :key="layer.id" class="q-mb-md" data-test="layer">
      <div class="row items-center no-wrap">
        <q-input
          :model-value="drafts[layer.id] ?? layer.title"
          dense
          borderless
          :aria-label="t('editor.layerName')"
          :input-class="layer.id === activeLayerId ? 'text-weight-bold' : ''"
          class="col"
          data-test="layer-title"
          @focus="emit('update:activeLayerId', layer.id)"
          @update:model-value="drafts[layer.id] = String($event ?? '')"
          @blur="onRename(layer)"
        />
        <q-btn
          flat
          dense
          round
          :icon="layer.visible ? 'visibility' : 'visibility_off'"
          :aria-label="layer.visible ? t('editor.visible') : t('editor.hidden')"
          data-test="toggle-layer"
          @click="emit('toggle-layer', layer.id, !layer.visible)"
        />
        <q-btn
          flat
          dense
          round
          icon="delete"
          color="negative"
          :aria-label="t('editor.deleteLayer')"
          data-test="delete-layer"
          @click="askDelete(layer)"
        />
      </div>
      <q-list dense>
        <q-item
          v-for="f in featuresOf(layer.id)"
          :key="f.id"
          clickable
          :active="f.id === selectedId"
          active-class="bg-blue-1"
          data-test="feature-item"
          @click="emit('select', f.id)"
        >
          <q-item-section>
            <q-item-label>{{ label(f) }}</q-item-label>
            <q-item-label caption>{{ typeLabel(f) }}</q-item-label>
          </q-item-section>
        </q-item>
        <q-item v-if="featuresOf(layer.id).length === 0"
          ><q-item-section class="swmap-muted">{{ t('editor.noFeatures') }}</q-item-section></q-item
        >
      </q-list>
    </div>
    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('editor.deleteLayer')"
      :message="t('editor.deleteLayerConfirm', { title: deleting?.title ?? '' })"
      @confirm="deleting && emit('delete-layer', deleting.id)"
    />
  </div>
</template>

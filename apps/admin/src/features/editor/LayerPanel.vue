<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FeatureData, LayerData } from '@sw-maps/map-contracts';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import SwmapCard from '@/components/ui/SwmapCard.vue';

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
  'move-layer': [id: number, direction: -1 | 1];
  'style-layer': [id: number, style: LayerData['style']];
  'delete-layer': [id: number];
  select: [id: number];
}>();
const { t } = useI18n();
const drafts = reactive<Record<number, string>>({});
const deleting = ref<LayerData | null>(null);
const confirmOpen = ref(false);
const expanded = ref<number | null>(null);
const forms = reactive<
  Record<number, { minZoom: string; maxZoom: string; weight: string; opacity: string }>
>({});

const dashOptions = computed(() => [
  { value: 'solid', label: t('feature.dashSolid') },
  { value: 'dashed', label: t('feature.dashDashed') },
  { value: 'dotted', label: t('feature.dashDotted') },
]);

function styleText(layer: LayerData, key: string): string {
  const value = layer.style[key];
  return typeof value === 'number' ? String(value) : '';
}

function toggleSettings(layer: LayerData): void {
  if (expanded.value === layer.id) {
    expanded.value = null;
    return;
  }
  expanded.value = layer.id;
  forms[layer.id] = {
    minZoom: styleText(layer, 'minZoom'),
    maxZoom: styleText(layer, 'maxZoom'),
    weight: styleText(layer, 'weight'),
    opacity: styleText(layer, 'opacity'),
  };
}

const RANGES = { minZoom: [0, 24], maxZoom: [0, 24], weight: [1, 20], opacity: [0, 1] } as const;

/** Empty clears the value (the default applies); other input is clamped. Only real changes are emitted. */
function commitLayerNumber(layer: LayerData, key: keyof typeof RANGES): void {
  const raw = forms[layer.id]?.[key] ?? '';
  const style: LayerData['style'] = { ...layer.style };
  if (raw.trim() === '' || !Number.isFinite(Number(raw))) {
    delete style[key];
  } else {
    const [min, max] = RANGES[key];
    style[key] = Math.min(max, Math.max(min, Number(raw)));
  }
  if (JSON.stringify(style) !== JSON.stringify(layer.style)) emit('style-layer', layer.id, style);
}

function commitLayerValue(layer: LayerData, key: 'color' | 'dash', value: string): void {
  emit('style-layer', layer.id, { ...layer.style, [key]: value });
}

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
  <SwmapCard class="swmap-editor__side" data-test="layer-panel">
    <div class="row items-center">
      <div class="text-subtitle1">{{ t('editor.layers') }}</div>
      <q-space />
      <q-btn
        flat
        dense
        round
        icon="r_add"
        :aria-label="t('editor.addLayer')"
        data-test="add-layer"
        @click="emit('add-layer')"
      />
    </div>
    <p v-if="layers.length === 0" class="swmap-muted">{{ t('editor.noLayers') }}</p>
    <div v-for="(layer, index) in layers" :key="layer.id" class="q-mb-md" data-test="layer">
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
          icon="r_arrow_upward"
          :aria-label="t('editor.moveUp')"
          :disable="index === 0"
          data-test="move-up"
          @click="emit('move-layer', layer.id, -1)"
        />
        <q-btn
          flat
          dense
          round
          icon="r_arrow_downward"
          :aria-label="t('editor.moveDown')"
          :disable="index === layers.length - 1"
          data-test="move-down"
          @click="emit('move-layer', layer.id, 1)"
        />
        <q-btn
          flat
          dense
          round
          icon="r_tune"
          :aria-label="t('editor.layerSettings')"
          :aria-expanded="expanded === layer.id"
          data-test="layer-settings"
          @click="toggleSettings(layer)"
        />
        <q-btn
          flat
          dense
          round
          :icon="layer.visible ? 'r_visibility' : 'r_visibility_off'"
          :aria-label="layer.visible ? t('editor.visible') : t('editor.hidden')"
          data-test="toggle-layer"
          @click="emit('toggle-layer', layer.id, !layer.visible)"
        />
        <q-btn
          flat
          dense
          round
          icon="r_delete"
          color="negative"
          :aria-label="t('editor.deleteLayer')"
          data-test="delete-layer"
          @click="askDelete(layer)"
        />
      </div>
      <div v-if="expanded === layer.id && forms[layer.id]" class="q-pa-sm" data-test="layer-form">
        <div class="row q-col-gutter-sm">
          <q-input
            v-model="forms[layer.id]!.minZoom"
            class="col-6"
            type="number"
            min="0"
            max="24"
            outlined
            dense
            :label="t('editor.layerMinZoom')"
            data-test="layer-min-zoom"
            @blur="commitLayerNumber(layer, 'minZoom')"
          />
          <q-input
            v-model="forms[layer.id]!.maxZoom"
            class="col-6"
            type="number"
            min="0"
            max="24"
            outlined
            dense
            :label="t('editor.layerMaxZoom')"
            data-test="layer-max-zoom"
            @blur="commitLayerNumber(layer, 'maxZoom')"
          />
          <q-input
            v-model="forms[layer.id]!.weight"
            class="col-6"
            type="number"
            min="1"
            max="20"
            outlined
            dense
            :label="t('feature.weight')"
            data-test="layer-weight"
            @blur="commitLayerNumber(layer, 'weight')"
          />
          <q-input
            v-model="forms[layer.id]!.opacity"
            class="col-6"
            type="number"
            min="0"
            max="1"
            step="0.1"
            outlined
            dense
            :label="t('feature.opacity')"
            data-test="layer-opacity"
            @blur="commitLayerNumber(layer, 'opacity')"
          />
          <q-select
            :model-value="typeof layer.style['dash'] === 'string' ? layer.style['dash'] : 'solid'"
            class="col-12"
            outlined
            dense
            emit-value
            map-options
            :options="dashOptions"
            :label="t('feature.dash')"
            data-test="layer-dash"
            @update:model-value="commitLayerValue(layer, 'dash', $event)"
          />
        </div>
        <div class="q-mt-sm">
          <div class="text-caption">{{ t('feature.color') }}</div>
          <input
            type="color"
            :value="typeof layer.style['color'] === 'string' ? layer.style['color'] : '#c0392b'"
            :aria-label="t('feature.color')"
            data-test="layer-color"
            @change="commitLayerValue(layer, 'color', ($event.target as HTMLInputElement).value)"
          />
        </div>
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
  </SwmapCard>
</template>

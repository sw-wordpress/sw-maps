<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MapData, ProviderData } from '@sw-maps/map-contracts';
import { getConfig, hasCapability } from '@/config';

const props = defineProps<{ map: MapData; providers: ProviderData[] }>();
const emit = defineEmits<{
  patch: [patch: Record<string, unknown>];
  'use-current-view': [];
}>();
const { t } = useI18n();
const canPublish = hasCapability(getConfig(), 'swmap_publish_maps');

const form = reactive({
  title: '',
  description: '',
  slug: '',
  lon: 0,
  lat: 0,
  zoom: 0,
  minZoom: 0,
  maxZoom: 19,
  height: 400,
  aspectRatio: '',
});

watch(
  () => props.map,
  (m) => {
    Object.assign(form, {
      title: m.title,
      description: m.description,
      slug: m.slug,
      lon: m.viewport.center[0],
      lat: m.viewport.center[1],
      zoom: m.viewport.zoom,
      minZoom: m.viewport.minZoom,
      maxZoom: m.viewport.maxZoom,
      height: m.settings.height,
      aspectRatio: m.settings.aspectRatio ?? '',
    });
  },
  { immediate: true, deep: true },
);

const providerOptions = computed(() => [
  { value: null, label: t('mapForm.defaultProvider') },
  ...props.providers.filter((p) => p.enabled).map((p) => ({ value: p.id, label: p.name })),
]);
const statusOptions = computed(() => [
  { value: 'draft', label: t('common.draft') },
  { value: 'published', label: t('common.published') },
]);

const toggles = [
  'scrollZoom',
  'touch',
  'drag',
  'zoomControls',
  'fullscreen',
  'scale',
  'showList',
  'clickToLoad',
] as const;

function commitText(field: 'title' | 'description' | 'slug'): void {
  if (form[field] !== props.map[field]) emit('patch', { [field]: form[field] });
}

function commitViewport(): void {
  emit('patch', {
    viewport: {
      center: [form.lon, form.lat],
      zoom: form.zoom,
      minZoom: form.minZoom,
      maxZoom: form.maxZoom,
    },
  });
}

function commitSettings(): void {
  emit('patch', {
    settings: {
      height: form.height,
      aspectRatio: form.aspectRatio.trim() === '' ? null : form.aspectRatio.trim(),
    },
  });
}
</script>

<template>
  <div data-test="map-form">
    <q-input
      v-model="form.title"
      outlined
      dense
      :label="t('mapForm.title')"
      class="swmap-field"
      data-test="title"
      @blur="commitText('title')"
    />
    <q-input
      v-model="form.description"
      type="textarea"
      autogrow
      outlined
      dense
      :label="t('mapForm.description')"
      class="swmap-field"
      data-test="description"
      @blur="commitText('description')"
    />
    <q-input
      v-model="form.slug"
      outlined
      dense
      :label="t('mapForm.slug')"
      class="swmap-field"
      data-test="slug"
      @blur="commitText('slug')"
    />
    <q-select
      :model-value="map.status"
      outlined
      dense
      emit-value
      map-options
      :options="statusOptions"
      :disable="!canPublish"
      :label="t('mapForm.status')"
      class="swmap-field"
      data-test="status"
      @update:model-value="emit('patch', { status: $event })"
    />
    <q-select
      :model-value="map.settings.providerId"
      outlined
      dense
      emit-value
      map-options
      :options="providerOptions"
      :label="t('mapForm.provider')"
      class="swmap-field"
      data-test="provider"
      @update:model-value="emit('patch', { settings: { providerId: $event } })"
    />

    <div class="text-subtitle2">{{ t('mapForm.view') }}</div>
    <div class="row q-col-gutter-sm swmap-field">
      <q-input
        v-model.number="form.lon"
        type="number"
        step="any"
        outlined
        dense
        :label="t('mapForm.longitude')"
        class="col"
        data-test="lon"
        @blur="commitViewport"
      />
      <q-input
        v-model.number="form.lat"
        type="number"
        step="any"
        outlined
        dense
        :label="t('mapForm.latitude')"
        class="col"
        data-test="lat"
        @blur="commitViewport"
      />
    </div>
    <div class="row q-col-gutter-sm swmap-field">
      <q-input
        v-model.number="form.zoom"
        type="number"
        step="any"
        outlined
        dense
        :label="t('mapForm.zoom')"
        class="col"
        data-test="zoom"
        @blur="commitViewport"
      />
      <q-input
        v-model.number="form.minZoom"
        type="number"
        outlined
        dense
        :label="t('mapForm.minZoom')"
        class="col"
        data-test="min-zoom"
        @blur="commitViewport"
      />
      <q-input
        v-model.number="form.maxZoom"
        type="number"
        outlined
        dense
        :label="t('mapForm.maxZoom')"
        class="col"
        data-test="max-zoom"
        @blur="commitViewport"
      />
    </div>
    <q-toggle
      :model-value="map.viewport.autoBounds"
      :label="t('mapForm.autoBounds')"
      data-test="auto-bounds"
      @update:model-value="emit('patch', { viewport: { autoBounds: $event } })"
    />
    <div class="swmap-field">
      <q-btn
        flat
        dense
        no-caps
        icon="r_my_location"
        :label="t('mapForm.useCurrentView')"
        data-test="use-view"
        @click="emit('use-current-view')"
      />
    </div>

    <div class="text-subtitle2">{{ t('mapForm.display') }}</div>
    <div class="row q-col-gutter-sm swmap-field">
      <q-input
        v-model.number="form.height"
        type="number"
        outlined
        dense
        :label="t('mapForm.height')"
        class="col"
        data-test="height"
        @blur="commitSettings"
      />
      <q-input
        v-model="form.aspectRatio"
        outlined
        dense
        :label="t('mapForm.aspectRatio')"
        :hint="t('mapForm.aspectHint')"
        class="col"
        data-test="aspect"
        @blur="commitSettings"
      />
    </div>
    <div>
      <q-toggle
        v-for="key in toggles"
        :key="key"
        :model-value="map.settings[key]"
        :label="t(`mapForm.${key}` as 'mapForm.scrollZoom')"
        :data-test="`toggle-${key}`"
        class="block"
        @update:model-value="emit('patch', { settings: { [key]: $event } })"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FeatureData } from '@sw-maps/map-contracts';
import { FEATURE_ICONS } from './icons';
import { isValidPosition, safeColor } from '@sw-maps/map-contracts';
import { pickImage } from '@/utils/media';

const props = defineProps<{ feature: FeatureData }>();
const emit = defineEmits<{
  patch: [patch: { properties?: Partial<FeatureData['properties']>; style?: FeatureData['style'] }];
  coordinates: [lon: number, lat: number];
  delete: [];
}>();
const { t } = useI18n();

const form = reactive({
  title: '',
  description: '',
  address: '',
  link: '',
  lon: 0,
  lat: 0,
  color: '#c0392b',
});
const imageUrl = ref<string | null>(null);
const coordinatesError = ref(false);
const linkError = ref(false);

const isPoint = computed(() => props.feature.geometry.type === 'Point');
const iconOptions = computed(() => [
  ...FEATURE_ICONS.map((name) => ({
    value: `builtin:${name}`,
    label: t(`feature.icons.${name}` as 'feature.icons.pin'),
  })),
  ...(props.feature.properties.icon.startsWith('media:')
    ? [{ value: props.feature.properties.icon, label: t('feature.mediaIcon') }]
    : []),
]);

watch(
  () => props.feature,
  (f) => {
    form.title = f.properties.title;
    form.description = f.properties.description;
    form.address = f.properties.address;
    form.link = f.properties.link ?? '';
    form.color = safeColor(f.style['color'], '#c0392b');
    if (f.geometry.type === 'Point') {
      const [lon, lat] = f.geometry.coordinates as [number, number];
      form.lon = lon;
      form.lat = lat;
    }
    coordinatesError.value = false;
    linkError.value = false;
  },
  { immediate: true, deep: true },
);

function commitText(field: 'title' | 'description' | 'address'): void {
  if (form[field] !== props.feature.properties[field])
    emit('patch', { properties: { [field]: form[field] } });
}

function commitLink(): void {
  const value = form.link.trim();
  linkError.value = value !== '' && !/^(https?:\/\/[^\s"<>\\]+|mailto:\S+|tel:\S+)$/i.test(value);
  if (!linkError.value && value !== (props.feature.properties.link ?? ''))
    emit('patch', { properties: { link: value === '' ? null : value } });
}

function commitCoordinates(): void {
  coordinatesError.value = !isValidPosition([form.lon, form.lat]);
  if (!coordinatesError.value) emit('coordinates', form.lon, form.lat);
}

function commitColor(value: string): void {
  form.color = value;
  emit('patch', { style: { ...props.feature.style, color: value } });
}

async function chooseImage(): Promise<void> {
  const image = await pickImage();
  if (!image) return;
  imageUrl.value = image.sizes?.['thumbnail']?.url ?? image.url;
  emit('patch', { properties: { image_id: image.id } });
}
</script>

<template>
  <div data-test="feature-form">
    <div class="text-caption swmap-muted q-mb-sm">
      {{ t('feature.geometry') }}:
      {{ t(`feature.type.${feature.geometry.type}` as 'feature.type.Point') }}
    </div>
    <q-input
      v-model="form.title"
      outlined
      dense
      :label="t('feature.title')"
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
      :label="t('feature.description')"
      class="swmap-field"
      data-test="description"
      @blur="commitText('description')"
    />
    <q-input
      v-model="form.address"
      outlined
      dense
      :label="t('feature.address')"
      class="swmap-field"
      data-test="address"
      @blur="commitText('address')"
    />
    <q-input
      v-model="form.link"
      outlined
      dense
      :label="t('feature.link')"
      class="swmap-field"
      :error="linkError"
      :error-message="t('feature.linkInvalid')"
      data-test="link"
      @blur="commitLink"
    />
    <q-select
      :model-value="feature.properties.icon"
      outlined
      dense
      emit-value
      map-options
      :options="iconOptions"
      :label="t('feature.icon')"
      class="swmap-field"
      data-test="icon"
      @update:model-value="emit('patch', { properties: { icon: $event } })"
    />
    <div class="swmap-field">
      <div class="text-caption">{{ t('feature.color') }}</div>
      <input
        type="color"
        :value="form.color"
        :aria-label="t('feature.color')"
        data-test="color"
        @change="commitColor(($event.target as HTMLInputElement).value)"
      />
    </div>
    <div class="swmap-field">
      <div class="text-caption">{{ t('feature.image') }}</div>
      <img v-if="imageUrl" :src="imageUrl" alt="" style="max-width: 100%; max-height: 120px" />
      <span v-else-if="feature.properties.image_id" class="swmap-muted"
        >#{{ feature.properties.image_id }}</span
      >
      <div>
        <q-btn
          flat
          dense
          no-caps
          icon="image"
          :label="t('feature.chooseImage')"
          data-test="choose-image"
          @click="chooseImage"
        />
        <q-btn
          v-if="feature.properties.image_id"
          flat
          dense
          no-caps
          icon="close"
          :label="t('feature.removeImage')"
          data-test="remove-image"
          @click="
            imageUrl = null;
            emit('patch', { properties: { image_id: null } });
          "
        />
      </div>
    </div>
    <div v-if="isPoint" class="swmap-field">
      <div class="text-caption">{{ t('feature.coordinates') }}</div>
      <div class="row q-col-gutter-sm">
        <q-input
          v-model.number="form.lon"
          type="number"
          step="any"
          outlined
          dense
          :label="t('feature.longitude')"
          class="col"
          data-test="lon"
          @blur="commitCoordinates"
        />
        <q-input
          v-model.number="form.lat"
          type="number"
          step="any"
          outlined
          dense
          :label="t('feature.latitude')"
          class="col"
          data-test="lat"
          @blur="commitCoordinates"
        />
      </div>
      <div
        v-if="coordinatesError"
        class="text-negative text-caption"
        role="alert"
        data-test="coord-error"
      >
        {{ t('feature.coordinatesInvalid') }}
      </div>
    </div>
    <q-btn
      flat
      no-caps
      color="negative"
      icon="delete"
      :label="t('feature.delete')"
      data-test="delete"
      @click="emit('delete')"
    />
  </div>
</template>

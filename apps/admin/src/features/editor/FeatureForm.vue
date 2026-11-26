<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CategoryData, FeatureData } from '@sw-maps/map-contracts';
import { FEATURE_ICONS } from './icons';
import { isValidPosition, lineLengthMeters, safeColor } from '@sw-maps/map-contracts';
import { pickImage } from '@/utils/media';

const props = defineProps<{
  feature: FeatureData;
  categories?: CategoryData[];
  canManageCategories?: boolean;
}>();
const emit = defineEmits<{
  patch: [
    patch: {
      properties?: Partial<FeatureData['properties']>;
      style?: FeatureData['style'];
      categories?: number[];
    },
  ];
  manageCategories: [];
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
  weight: 3,
  opacity: 1,
  fillOpacity: 0.25,
});
const imageUrl = ref<string | null>(null);
const coordinatesError = ref(false);
const linkError = ref(false);

const fieldRows = ref<{ label: string; value: string }[]>([]);
const MAX_FIELDS = 10;

const isPoint = computed(() => props.feature.geometry.type === 'Point');
const isArea = computed(() => /Polygon$/.test(props.feature.geometry.type));
const isLine = computed(() => !isPoint.value && !/^MultiPoint$/.test(props.feature.geometry.type));
const lengthLabel = computed(() => {
  const meters = lineLengthMeters(props.feature.geometry);
  if (meters <= 0) return '';
  return meters >= 1000 ? `${(meters / 1000).toFixed(2)} km` : `${Math.round(meters)} m`;
});
const categoryOptions = computed(() =>
  (props.categories ?? []).map((c) => ({ value: c.id, label: c.title })),
);
const dashOptions = computed(() => [
  { value: 'solid', label: t('feature.dashSolid') },
  { value: 'dashed', label: t('feature.dashDashed') },
  { value: 'dotted', label: t('feature.dashDotted') },
]);

function styleNumber(key: string, fallback: number, feature: FeatureData = props.feature): number {
  const value = feature.style[key];
  return typeof value === 'number' ? value : fallback;
}

const LIMITS = { weight: [1, 20], opacity: [0, 1], fillOpacity: [0, 1] } as const;

/** Clamps to the allowed range and stores the number only when it differs from the current value. */
function commitNumber(key: keyof typeof LIMITS, fallback: number): void {
  const [min, max] = LIMITS[key];
  const raw = Number(form[key]);
  const value = Number.isFinite(raw) ? Math.min(max, Math.max(min, raw)) : fallback;
  form[key] = value;
  if (value !== styleNumber(key, fallback)) commitStyle(key, value);
}

/** Line and area style (weight, dash, opacity); empty values fall back to the layer or default style. */
function commitStyle(key: string, value: string | number): void {
  emit('patch', { style: { ...props.feature.style, [key]: value } });
}
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
    fieldRows.value = (f.properties.fields ?? []).map((row) => ({ ...row }));
    form.weight = styleNumber('weight', 3, f);
    form.opacity = styleNumber('opacity', 1, f);
    form.fillOpacity = styleNumber('fillOpacity', 0.25, f);
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

/** Rows without a label are kept in the form until one is entered; only complete rows are stored. */
function commitFields(): void {
  const complete = fieldRows.value
    .map((row) => ({ label: row.label.trim(), value: row.value.trim() }))
    .filter((row) => row.label !== '');
  const current = props.feature.properties.fields ?? [];
  if (JSON.stringify(complete) !== JSON.stringify(current))
    emit('patch', { properties: { fields: complete } });
}

function removeField(index: number): void {
  fieldRows.value.splice(index, 1);
  commitFields();
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

async function chooseIcon(): Promise<void> {
  const image = await pickImage();
  if (image) {
    // The URL lets the editor canvas draw the icon right away (the server resolves it again on reload).
    emit('patch', {
      properties: {
        icon: `media:${image.id}`,
        icon_url: image.sizes?.['thumbnail']?.url ?? image.url,
      },
    });
  }
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
      <template v-if="lengthLabel">
        · {{ t('feature.length') }}: <span data-test="length">{{ lengthLabel }}</span>
      </template>
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
    <div v-if="categoryOptions.length > 0 || canManageCategories" class="swmap-field">
      <q-select
        :model-value="feature.categories ?? []"
        multiple
        use-chips
        outlined
        dense
        emit-value
        map-options
        :options="categoryOptions"
        :label="t('feature.categories')"
        data-test="categories"
        @update:model-value="emit('patch', { categories: $event })"
      />
      <q-btn
        v-if="canManageCategories"
        flat
        dense
        no-caps
        icon="r_label"
        :label="t('categories.manage')"
        data-test="manage-categories"
        @click="emit('manageCategories')"
      />
    </div>
    <div class="swmap-field" data-test="fields">
      <div class="text-caption">{{ t('feature.fields') }}</div>
      <div
        v-for="(row, index) in fieldRows"
        :key="index"
        class="row q-col-gutter-xs items-center no-wrap q-mb-xs"
        data-test="field-row"
      >
        <q-input
          v-model="row.label"
          class="col"
          outlined
          dense
          :label="t('feature.fieldLabel')"
          data-test="field-label"
          @blur="commitFields"
        />
        <q-input
          v-model="row.value"
          class="col"
          outlined
          dense
          :label="t('feature.fieldValue')"
          data-test="field-value"
          @blur="commitFields"
        />
        <q-btn
          flat
          dense
          round
          icon="r_close"
          :aria-label="t('common.delete')"
          data-test="field-remove"
          @click="removeField(index)"
        />
      </div>
      <q-btn
        v-if="fieldRows.length < MAX_FIELDS"
        flat
        dense
        no-caps
        icon="r_add"
        :label="t('feature.addField')"
        data-test="field-add"
        @click="fieldRows.push({ label: '', value: '' })"
      />
    </div>
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
      <q-btn
        flat
        dense
        no-caps
        icon="r_add_photo_alternate"
        :label="t('feature.chooseIcon')"
        data-test="choose-icon"
        @click="chooseIcon"
      />
    </div>
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
    <template v-if="isLine">
      <q-input
        v-model.number="form.weight"
        type="number"
        min="1"
        max="20"
        step="1"
        outlined
        dense
        :label="t('feature.weight')"
        class="swmap-field"
        data-test="weight"
        @blur="commitNumber('weight', 3)"
      />
      <q-select
        :model-value="typeof feature.style['dash'] === 'string' ? feature.style['dash'] : 'solid'"
        outlined
        dense
        emit-value
        map-options
        :options="dashOptions"
        :label="t('feature.dash')"
        class="swmap-field"
        data-test="dash"
        @update:model-value="commitStyle('dash', $event)"
      />
      <q-input
        v-model.number="form.opacity"
        type="number"
        min="0"
        max="1"
        step="0.1"
        outlined
        dense
        :label="t('feature.opacity')"
        class="swmap-field"
        data-test="opacity"
        @blur="commitNumber('opacity', 1)"
      />
      <q-input
        v-if="isArea"
        v-model.number="form.fillOpacity"
        type="number"
        min="0"
        max="1"
        step="0.05"
        outlined
        dense
        :label="t('feature.fillOpacity')"
        class="swmap-field"
        data-test="fill-opacity"
        @blur="commitNumber('fillOpacity', 0.25)"
      />
    </template>
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
          icon="r_image"
          :label="t('feature.chooseImage')"
          data-test="choose-image"
          @click="chooseImage"
        />
        <q-btn
          v-if="feature.properties.image_id"
          flat
          dense
          no-caps
          icon="r_close"
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
      icon="r_delete"
      :label="t('feature.delete')"
      data-test="delete"
      @click="emit('delete')"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api';
import type { GeocodeHit } from '@/api';
import { isApiError } from '@/api/client';
import { errorMessage } from '@/utils/errors';

const props = defineProps<{
  modelValue: boolean;
  mode: 'search' | 'reverse';
  query?: string;
  lon?: number;
  lat?: number;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; choose: [hit: GeocodeHit] }>();
const { t } = useI18n();

const loading = ref(false);
const failure = ref('');
const hits = ref<GeocodeHit[]>([]);
const selected = ref(0);
const done = ref(false);

/** A lookup runs only when the dialog opens (an explicit action): no typing triggers a request. */
async function run(): Promise<void> {
  loading.value = true;
  failure.value = '';
  hits.value = [];
  selected.value = 0;
  done.value = false;
  try {
    if (props.mode === 'search') {
      hits.value = (await api().geocoding.search(props.query ?? '')).results;
    } else {
      const { result } = await api().geocoding.reverse(props.lon ?? 0, props.lat ?? 0);
      hits.value = result ? [result] : [];
    }
    done.value = true;
  } catch (error) {
    failure.value =
      isApiError(error) && error.reason
        ? t(`geocoding.reason.${error.reason}`)
        : errorMessage(error, t('geocoding.failed'));
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void run();
  },
  { immediate: true },
);

function choose(): void {
  const hit = hits.value[selected.value];
  if (!hit) return;
  emit('choose', hit);
  emit('update:modelValue', false);
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="width: min(560px, 94vw)" data-test="geocode-dialog">
      <q-card-section>
        <div class="text-h6">
          {{ mode === 'search' ? t('geocoding.searchTitle') : t('geocoding.reverseTitle') }}
        </div>
        <p class="swmap-muted q-mb-none">{{ t('geocoding.privacy') }}</p>
      </q-card-section>
      <q-card-section>
        <q-inner-loading :showing="loading" />
        <q-banner
          v-if="failure"
          class="bg-negative text-white"
          rounded
          dense
          data-test="geocode-failure"
        >
          {{ failure }}
        </q-banner>
        <p v-else-if="done && hits.length === 0" data-test="geocode-empty">
          {{ t('geocoding.none') }}
        </p>
        <q-option-group
          v-if="hits.length > 0"
          v-model="selected"
          type="radio"
          :options="hits.map((h, i) => ({ value: i, label: h.label }))"
          data-test="geocode-hits"
        />
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat no-caps :label="t('common.cancel')" @click="emit('update:modelValue', false)" />
        <q-btn
          color="primary"
          no-caps
          :label="t('geocoding.use')"
          :disable="hits.length === 0"
          data-test="geocode-use"
          @click="choose"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

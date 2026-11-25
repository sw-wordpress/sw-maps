<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProviderData } from '@sw-maps/map-contracts';
import { isApiError } from '@/api/client';

const props = defineProps<{
  modelValue: boolean;
  provider: ProviderData | null;
  saving: boolean;
  error: unknown;
}>();
const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  save: [body: Record<string, unknown>];
}>();
const { t } = useI18n();

const form = reactive({
  name: '',
  urlTemplate: '',
  attribution: '',
  minZoom: 0,
  maxZoom: 19,
  tileSize: 256,
  publicKey: '',
  secretConstant: '',
});
const fieldErrors = ref<Record<string, string>>({});

watch(
  () => [props.modelValue, props.provider] as const,
  ([open, provider]) => {
    if (!open) return;
    const c = provider?.public_config;
    Object.assign(form, {
      name: provider?.name ?? '',
      urlTemplate: c?.urlTemplate ?? '',
      attribution: c?.attribution ?? '',
      minZoom: c?.minZoom ?? 0,
      maxZoom: c?.maxZoom ?? 19,
      tileSize: c?.tileSize ?? 256,
      publicKey: c?.publicKey ?? '',
      secretConstant: provider?.secret_constant ?? '',
    });
    fieldErrors.value = {};
  },
  { immediate: true },
);

watch(
  () => props.error,
  (error) => {
    const next: Record<string, string> = {};
    if (isApiError(error)) for (const d of error.details) next[d.field] = d.message;
    fieldErrors.value = next;
  },
);

const err = (field: string): string | undefined => fieldErrors.value[field];

function submit(): void {
  emit('save', {
    name: form.name,
    secret_constant: form.secretConstant === '' ? null : form.secretConstant,
    public_config: {
      urlTemplate: form.urlTemplate,
      attribution: form.attribution,
      minZoom: form.minZoom,
      maxZoom: form.maxZoom,
      tileSize: form.tileSize,
      publicKey: form.publicKey,
    },
  });
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="width: min(560px, 94vw)">
      <q-form @submit.prevent="submit">
        <q-card-section>
          <div class="text-h6">{{ provider ? t('providers.edit') : t('providers.add') }}</div>
        </q-card-section>
        <q-card-section>
          <q-input
            v-model="form.name"
            outlined
            dense
            :label="t('providers.name')"
            class="swmap-field"
            :error="!!err('name')"
            :error-message="err('name')"
            data-test="name"
          />
          <q-input
            v-model="form.urlTemplate"
            outlined
            dense
            :label="t('providers.urlTemplate')"
            :hint="t('providers.urlHint')"
            class="swmap-field"
            :error="!!err('public_config.urlTemplate')"
            :error-message="err('public_config.urlTemplate')"
            data-test="url"
          />
          <q-input
            v-model="form.attribution"
            outlined
            dense
            :label="t('providers.attribution')"
            :hint="t('providers.attributionHint')"
            class="swmap-field"
            :error="!!err('public_config.attribution')"
            :error-message="err('public_config.attribution')"
            data-test="attribution"
          />
          <div class="row q-col-gutter-sm swmap-field">
            <q-input
              v-model.number="form.minZoom"
              type="number"
              outlined
              dense
              :label="t('providers.minZoom')"
              class="col"
            />
            <q-input
              v-model.number="form.maxZoom"
              type="number"
              outlined
              dense
              :label="t('providers.maxZoom')"
              class="col"
            />
            <q-select
              v-model="form.tileSize"
              :options="[256, 512]"
              outlined
              dense
              :label="t('providers.tileSize')"
              class="col"
            />
          </div>
          <q-input
            v-model="form.publicKey"
            outlined
            dense
            :label="t('providers.publicKey')"
            class="swmap-field"
          />
          <q-input
            v-model="form.secretConstant"
            outlined
            dense
            :label="t('providers.secretConstant')"
            class="swmap-field"
            :error="!!err('secret_constant')"
            :error-message="err('secret_constant')"
            data-test="secret"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            flat
            no-caps
            :label="t('common.cancel')"
            @click="emit('update:modelValue', false)"
          />
          <q-btn
            type="submit"
            color="primary"
            no-caps
            :label="t('common.save')"
            :loading="saving"
            data-test="save"
          />
        </q-card-actions>
      </q-form>
    </q-card>
  </q-dialog>
</template>

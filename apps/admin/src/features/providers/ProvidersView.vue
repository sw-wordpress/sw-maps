<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProviderData } from '@sw-maps/map-contracts';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useNotify } from '@/composables/useNotify';
import { useProvidersStore } from '@/stores/providers';
import { errorMessage } from '@/utils/errors';
import ProviderDialog from './ProviderDialog.vue';

const { t } = useI18n();
const store = useProvidersStore();
const notify = useNotify();

const editing = ref<ProviderData | null>(null);
const dialogOpen = ref(false);
const saving = ref(false);
const saveError = ref<unknown>(null);
const enabling = ref<ProviderData | null>(null);
const enableOpen = ref(false);
const deleting = ref<ProviderData | null>(null);
const deleteOpen = ref(false);

async function run(action: () => Promise<unknown>, success?: string): Promise<boolean> {
  try {
    await action();
    if (success) notify.success(success);
    return true;
  } catch (error) {
    notify.error(errorMessage(error, t('app.saveFailed')));
    return false;
  }
}

onMounted(() => void run(() => store.load()));

function openDialog(provider: ProviderData | null): void {
  editing.value = provider;
  saveError.value = null;
  dialogOpen.value = true;
}

async function save(body: Record<string, unknown>): Promise<void> {
  saving.value = true;
  saveError.value = null;
  try {
    await store.save(editing.value?.id ?? null, body);
    dialogOpen.value = false;
    notify.success(t('providers.saved'));
  } catch (error) {
    saveError.value = error;
    notify.error(errorMessage(error, t('app.saveFailed')));
  } finally {
    saving.value = false;
  }
}

/** Enabling shows the external requests first (privacy); disabling needs no confirmation. */
function toggle(provider: ProviderData): void {
  if (provider.enabled) {
    void run(() => store.setEnabled(provider.id, false));
    return;
  }
  enabling.value = provider;
  enableOpen.value = true;
}

async function test(provider: ProviderData): Promise<void> {
  try {
    const result = await store.test(provider.id);
    if (result.ok) notify.success(t('providers.testOk'));
    else notify.error(`${t('providers.testFailed')} ${result.message}`);
  } catch (error) {
    notify.error(errorMessage(error, t('providers.testFailed')));
  }
}
</script>

<template>
  <div>
    <div class="swmap-toolbar">
      <h2 class="text-h6 q-my-none">{{ t('providers.title') }}</h2>
      <q-space />
      <q-btn
        color="primary"
        no-caps
        icon="r_add"
        :label="t('providers.add')"
        data-test="add"
        @click="openDialog(null)"
      />
    </div>
    <q-banner class="q-mb-md" rounded dense data-test="osm-notice">{{
      t('providers.osmNotice')
    }}</q-banner>
    <p v-if="store.items.length === 0 && !store.loading" class="swmap-muted">
      {{ t('providers.empty') }}
    </p>
    <div v-for="p in store.items" :key="p.id" class="swmap-card q-mb-md" data-test="provider">
      <div class="row items-center q-gutter-sm">
        <div class="text-subtitle1">{{ p.name }}</div>
        <q-toggle
          :model-value="p.enabled"
          :label="p.enabled ? t('common.enabled') : t('common.disabled')"
          data-test="toggle"
          @update:model-value="toggle(p)"
        />
        <q-space />
        <q-btn
          flat
          dense
          no-caps
          icon="r_wifi_tethering"
          :label="t('providers.test')"
          data-test="test"
          @click="test(p)"
        />
        <q-btn
          flat
          dense
          round
          icon="r_edit"
          :aria-label="t('common.edit')"
          data-test="edit"
          @click="openDialog(p)"
        />
        <q-btn
          flat
          dense
          round
          icon="r_delete"
          color="negative"
          :aria-label="t('common.delete')"
          data-test="delete"
          @click="
            deleting = p;
            deleteOpen = true;
          "
        />
      </div>
      <div class="swmap-muted">{{ p.public_config.urlTemplate }}</div>
      <div class="swmap-muted">
        {{ t('providers.hosts') }}: {{ (p.external_requests ?? []).join(', ') }}
        <template v-if="p.secret_constant">
          · {{ p.secret_constant }}:
          {{ p.secret_defined ? t('providers.secretDefined') : t('providers.secretMissing') }}
        </template>
      </div>
      <div
        v-if="store.tests[p.id]"
        class="q-mt-xs"
        :class="store.tests[p.id]?.ok ? 'text-positive' : 'text-negative'"
        data-test="test-result"
      >
        {{ store.tests[p.id]?.message }}
      </div>
    </div>

    <ProviderDialog
      v-model="dialogOpen"
      :provider="editing"
      :saving="saving"
      :error="saveError"
      @save="save"
    />
    <ConfirmDialog
      v-model="enableOpen"
      :title="t('providers.externalTitle')"
      :message="`${t('providers.externalBody')} ${(enabling?.external_requests ?? []).join(', ')}`"
      :confirm-label="t('providers.enable')"
      color="primary"
      @confirm="enabling && run(() => store.setEnabled(enabling!.id, true))"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('common.delete')"
      :message="t('providers.deleteConfirm', { name: deleting?.name ?? '' })"
      @confirm="deleting && run(() => store.remove(deleting!.id), t('providers.deleted'))"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SettingsData } from '@/api';
import { useNotify } from '@/composables/useNotify';
import { useSettingsStore } from '@/stores/settings';
import { api } from '@/api';
import { downloadFile } from '@/utils/download';
import { errorMessage } from '@/utils/errors';

const { t } = useI18n();
const store = useSettingsStore();
const notify = useNotify();
const form = reactive<SettingsData>({
  delete_data_on_uninstall: false,
  default_height: 400,
  default_scroll_zoom: false,
  max_features_per_layer: 5000,
  click_to_load: false,
  allowed_hosts: [],
  geocoder_url: '',
  geocoder_contact: '',
});
/** One host name per line (the API stores a list). */
const allowedHostsText = ref('');
const saving = ref(false);
const loaded = ref(false);

const capabilities: Array<{ cap: string; roles: string[] }> = [
  { cap: 'swmap_edit_maps', roles: ['administrator', 'editor'] },
  { cap: 'swmap_publish_maps', roles: ['administrator', 'editor'] },
  { cap: 'swmap_manage_layers', roles: ['administrator', 'editor'] },
  { cap: 'swmap_manage_imports', roles: ['administrator'] },
  { cap: 'swmap_manage_system', roles: ['administrator'] },
];

onMounted(async () => {
  try {
    await store.load();
    if (store.settings) Object.assign(form, store.settings);
    allowedHostsText.value = form.allowed_hosts.join('\n');
    loaded.value = true;
  } catch (error) {
    notify.error(errorMessage(error, t('app.loadFailed')));
  }
});

/** Everything the plugin stores about maps, as one file (useful before enabling data deletion on uninstall). */
async function exportAll(): Promise<void> {
  try {
    downloadFile(await api().exports.all());
  } catch (error) {
    notify.error(errorMessage(error, t('settings.exportFailed')));
  }
}

async function save(): Promise<void> {
  saving.value = true;
  try {
    const hosts = allowedHostsText.value
      .split(/\s*[\n,]\s*/)
      .map((h) => h.trim())
      .filter((h) => h !== '');
    await store.save({ ...form, allowed_hosts: hosts });
    notify.success(t('settings.saved'));
  } catch (error) {
    notify.error(errorMessage(error, t('app.saveFailed')));
  } finally {
    saving.value = false;
  }
}
import PageHeader from '@/components/ui/PageHeader.vue';
import SwmapCard from '@/components/ui/SwmapCard.vue';
import DataState from '@/components/ui/DataState.vue';
</script>

<template>
  <div>
    <PageHeader :title="t('settings.title')" />
    <SwmapCard v-if="loaded" icon="r_tune" style="max-width: 760px">
      <form @submit.prevent="save">
        <h3 class="text-subtitle1">{{ t('settings.defaults') }}</h3>
        <q-input
          v-model.number="form.default_height"
          type="number"
          outlined
          dense
          :min="120"
          :max="2000"
          :label="t('settings.defaultHeight')"
          class="swmap-field"
          data-test="height"
        />
        <q-toggle v-model="form.default_scroll_zoom" :label="t('settings.defaultScrollZoom')" />
        <q-toggle v-model="form.click_to_load" :label="t('settings.clickToLoad')" />
        <h3 class="text-subtitle1 q-mt-md">{{ t('settings.limits') }}</h3>
        <q-input
          v-model.number="form.max_features_per_layer"
          type="number"
          outlined
          dense
          :min="1"
          :max="50000"
          :label="t('settings.maxFeatures')"
          class="swmap-field"
          data-test="max-features"
        />
        <h3 class="text-subtitle1 q-mt-md">{{ t('settings.geocoding') }}</h3>
        <q-input
          v-model="form.geocoder_url"
          outlined
          dense
          :label="t('settings.geocoderUrl')"
          class="swmap-field"
          data-test="geocoder-url"
        />
        <q-input
          v-model="form.geocoder_contact"
          outlined
          dense
          :label="t('settings.geocoderContact')"
          class="swmap-field"
          data-test="geocoder-contact"
        />
        <q-input
          v-model="allowedHostsText"
          type="textarea"
          autogrow
          outlined
          dense
          :label="t('settings.allowedHosts')"
          class="swmap-field"
          data-test="allowed-hosts"
        />
        <h3 class="text-subtitle1 q-mt-md">{{ t('settings.uninstall') }}</h3>
        <q-btn
          flat
          no-caps
          icon="r_download"
          :label="t('settings.exportAll')"
          class="q-mb-sm"
          data-test="export-all"
          @click="exportAll"
        />
        <div />
        <q-toggle
          v-model="form.delete_data_on_uninstall"
          color="negative"
          :label="t('settings.deleteData')"
          data-test="delete-data"
        />
        <q-banner
          v-if="form.delete_data_on_uninstall"
          class="q-mt-sm"
          rounded
          dense
          data-test="delete-warning"
        >
          {{ t('settings.deleteWarning') }}
        </q-banner>
        <div class="q-mt-md">
          <q-btn
            type="submit"
            color="primary"
            no-caps
            :label="t('common.save')"
            :loading="saving"
            data-test="save"
          />
        </div>
      </form>
      <h3 class="text-subtitle1 q-mt-lg">{{ t('settings.permissions') }}</h3>
      <q-markup-table flat dense>
        <thead>
          <tr>
            <th scope="row" class="text-left">{{ t('settings.capability') }}</th>
            <th scope="row" class="text-left">{{ t('settings.roles') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in capabilities" :key="row.cap" data-test="cap-row">
            <td>{{ t(`settings.caps.${row.cap}` as 'settings.caps.swmap_edit_maps') }}</td>
            <td>
              {{
                row.roles
                  .map((r) => t(`settings.roleNames.${r}` as 'settings.roleNames.administrator'))
                  .join(', ')
              }}
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </SwmapCard>
    <DataState v-else :loading="true" :empty="true" :error="null" variant="block" />
  </div>
</template>

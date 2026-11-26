<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';
import { api } from '@/api';
import type { ProviderCheck } from '@/api';
import { useSettingsStore } from '@/stores/settings';
import { errorMessage } from '@/utils/errors';

const { t } = useI18n();
const store = useSettingsStore();
const notify = useNotify();

const checks = ref<ProviderCheck[] | null>(null);
const checking = ref(false);

/** Contacts every enabled provider (only on request: it makes requests to external servers). */
async function checkProviders(): Promise<void> {
  checking.value = true;
  try {
    checks.value = (await api().status.providers()).providers;
  } catch (error) {
    notify.error(errorMessage(error, t('app.loadFailed')));
  } finally {
    checking.value = false;
  }
}

const formatBytes = (n: number): string =>
  n >= 1048576 ? `${(n / 1048576).toFixed(0)} MiB` : `${Math.round(n / 1024)} KiB`;

onMounted(async () => {
  try {
    await store.loadStatus();
  } catch (error) {
    notify.error(errorMessage(error, t('app.loadFailed')));
  }
});
import PageHeader from '@/components/ui/PageHeader.vue';
import SwmapCard from '@/components/ui/SwmapCard.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import DataState from '@/components/ui/DataState.vue';
</script>

<template>
  <div>
    <PageHeader :title="t('status.title')" />
    <SwmapCard v-if="store.status" icon="r_monitor_heart" :title="t('status.title')">
      <q-markup-table flat dense>
        <tbody>
          <tr>
            <th scope="row" class="text-left">{{ t('status.plugin') }}</th>
            <td data-test="plugin">{{ store.status.plugin_version }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.schema') }}</th>
            <td data-test="schema">
              {{ store.status.db_version }} / {{ store.status.db_expected }}
              <StatusChip
                dense
                :kind="store.status.schema_ok ? 'success' : 'danger'"
                :icon="store.status.schema_ok ? 'r_check_circle' : 'r_error'"
                :label="store.status.schema_ok ? t('status.ok') : t('status.schemaProblem')"
                data-test="schema-state"
              />
            </td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.php') }}</th>
            <td>{{ store.status.php_version }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.wordpress') }}</th>
            <td>{{ store.status.wp_version }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.database') }}</th>
            <td>{{ store.status.db_server }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.multisite') }}</th>
            <td>{{ store.status.multisite ? t('common.yes') : t('common.no') }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.environment') }}</th>
            <td>{{ store.status.environment }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.consentApi') }}</th>
            <td data-test="consent">
              {{ store.status.consent_api ? t('status.active') : t('status.inactive') }}
            </td>
          </tr>
        </tbody>
      </q-markup-table>
      <h3 class="text-subtitle1 q-mt-md">{{ t('status.limits') }}</h3>
      <q-markup-table flat dense data-test="limits">
        <tbody>
          <tr>
            <th scope="row" class="text-left">{{ t('status.maxFeatures') }}</th>
            <td>{{ store.status.limits.max_features_per_layer }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.importSize') }}</th>
            <td>{{ formatBytes(store.status.limits.import_max_bytes) }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.importCoords') }}</th>
            <td>{{ store.status.limits.import_max_coords }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.pageSize') }}</th>
            <td>{{ store.status.limits.public_page_size }}</td>
          </tr>
          <tr>
            <th scope="row" class="text-left">{{ t('status.phpLimits') }}</th>
            <td>
              {{ store.status.limits.php_memory_limit }} ·
              {{ store.status.limits.php_max_execution_time }} s ·
              {{ store.status.limits.php_post_max_size }}
            </td>
          </tr>
        </tbody>
      </q-markup-table>
      <h3 class="text-subtitle1 q-mt-md">{{ t('status.providers') }}</h3>
      <q-btn
        outline
        no-caps
        icon="r_wifi_tethering"
        :label="t('status.checkProviders')"
        :loading="checking"
        data-test="check-providers"
        @click="checkProviders"
      />
      <ul v-if="checks" class="swmap-stack q-mt-sm">
        <li v-for="c in checks" :key="c.id" data-test="provider-check">
          {{ c.name }} ({{ c.host }}):
          <StatusChip
            dense
            :kind="c.ok ? 'success' : 'danger'"
            :icon="c.ok ? 'r_check_circle' : 'r_error'"
            :label="c.ok ? t('status.reachable') : c.message"
          />
        </li>
        <li v-if="checks.length === 0" class="swmap-muted">{{ t('status.noProviders') }}</li>
      </ul>
      <h3 class="text-subtitle1 q-mt-md">{{ t('status.tables') }}</h3>
      <ul class="swmap-stack">
        <li v-for="table in store.status.tables" :key="table.name" data-test="table">
          {{ table.name }}:
          <StatusChip
            dense
            :kind="table.exists ? 'success' : 'danger'"
            :icon="table.exists ? 'r_check_circle' : 'r_error'"
            :label="table.exists ? t('status.ok') : t('status.missing')"
          />
        </li>
      </ul>
    </SwmapCard>
    <DataState v-else :loading="true" :empty="true" :error="null" variant="block" />
  </div>
</template>

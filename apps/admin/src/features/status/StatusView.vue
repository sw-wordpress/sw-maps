<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';
import { useSettingsStore } from '@/stores/settings';
import { errorMessage } from '@/utils/errors';

const { t } = useI18n();
const store = useSettingsStore();
const notify = useNotify();

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

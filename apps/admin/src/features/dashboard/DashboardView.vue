<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig, hasCapability } from '@/config';
import { api } from '@/api';
import { useProvidersStore } from '@/stores/providers';
import { useNotify } from '@/composables/useNotify';
import { errorMessage } from '@/utils/errors';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import SwmapCard from '@/components/ui/SwmapCard.vue';

const { t } = useI18n();
const providers = useProvidersStore();
const notify = useNotify();
const canManage = hasCapability(getConfig(), 'swmap_manage_system');
const ready = ref(false);

const stats = ref({ total: 0, published: 0, drafts: 0, features: 0 });
const MAX_PAGES = 100;

/** Aggregates over every page (the list endpoint is paginated), without touching the shared list store. */
async function loadStats(): Promise<void> {
  const next = { total: 0, published: 0, drafts: 0, features: 0 };
  for (let page = 1; page <= MAX_PAGES; page++) {
    const result = await api().maps.list({ page, per_page: 100 });
    for (const map of result.items) {
      if (map.status === 'published') next.published++;
      else next.drafts++;
      next.features += map.feature_count ?? 0;
    }
    next.total = result.total;
    if (page >= result.totalPages) break;
  }
  stats.value = next;
}

async function load(): Promise<void> {
  try {
    await Promise.all([loadStats(), canManage ? providers.load() : Promise.resolve()]);
  } catch (error) {
    notify.error(errorMessage(error, t('app.loadFailed')));
  } finally {
    ready.value = true;
  }
}

function reload(): void {
  ready.value = false;
  void load();
}

onMounted(load);
</script>

<template>
  <div>
    <PageHeader :title="t('nav.dashboard')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.reload')"
        :loading="!ready"
        data-test="refresh"
        @click="reload"
      />
    </PageHeader>
    <div v-if="ready">
      <div class="swmap-grid">
        <StatTile
          data-test="stat-maps"
          icon="r_map"
          :label="t('dashboard.maps')"
          :value="String(stats.total)"
        />
        <StatTile
          data-test="stat-published"
          icon="r_public"
          tone="success"
          :label="t('dashboard.published')"
          :value="String(stats.published)"
        />
        <StatTile
          data-test="stat-drafts"
          icon="r_edit_note"
          :label="t('dashboard.drafts')"
          :value="String(stats.drafts)"
        />
        <StatTile
          data-test="stat-features"
          icon="r_place"
          :label="t('dashboard.features')"
          :value="String(stats.features)"
        />
      </div>
      <SwmapCard
        v-if="canManage"
        class="q-mt-md"
        icon="r_layers"
        :title="t('dashboard.provider')"
        data-test="provider-card"
      >
        <p v-if="providers.enabled.length === 0" class="swmap-muted">
          {{ t('dashboard.noProvider') }}
        </p>
        <ul v-else class="swmap-stack">
          <li v-for="p in providers.enabled" :key="p.id">
            <strong>{{ p.name }}</strong>
            <span class="swmap-muted">
              – {{ t('dashboard.externalRequests') }}
              {{ (p.external_requests ?? []).join(', ') }}</span
            >
          </li>
        </ul>
      </SwmapCard>
      <q-btn
        class="q-mt-md"
        color="primary"
        no-caps
        icon="r_add"
        :label="t('dashboard.newMap')"
        to="/maps"
      />
    </div>
    <DataState v-else :loading="true" :error="null" variant="cards" />
  </div>
</template>

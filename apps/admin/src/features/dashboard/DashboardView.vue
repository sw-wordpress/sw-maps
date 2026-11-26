<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig, hasCapability } from '@/config';
import { api } from '@/api';
import { useProvidersStore } from '@/stores/providers';
import { useNotify } from '@/composables/useNotify';
import { errorMessage } from '@/utils/errors';

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

onMounted(async () => {
  try {
    await Promise.all([loadStats(), canManage ? providers.load() : Promise.resolve()]);
  } catch (error) {
    notify.error(errorMessage(error, t('app.loadFailed')));
  } finally {
    ready.value = true;
  }
});
</script>

<template>
  <div v-if="ready">
    <div class="swmap-grid">
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-maps">{{ stats.total }}</div>
        <div class="swmap-stat__label">{{ t('dashboard.maps') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-published">{{ stats.published }}</div>
        <div class="swmap-stat__label">{{ t('dashboard.published') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-drafts">
          {{ stats.drafts }}
        </div>
        <div class="swmap-stat__label">{{ t('dashboard.drafts') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-features">{{ stats.features }}</div>
        <div class="swmap-stat__label">{{ t('dashboard.features') }}</div>
      </div>
    </div>
    <div v-if="canManage" class="swmap-card q-mt-md" data-test="provider-card">
      <div class="text-subtitle1">{{ t('dashboard.provider') }}</div>
      <p v-if="providers.enabled.length === 0" class="swmap-muted">
        {{ t('dashboard.noProvider') }}
      </p>
      <ul v-else>
        <li v-for="p in providers.enabled" :key="p.id">
          {{ p.name }}
          <span class="swmap-muted"
            >– {{ t('dashboard.externalRequests') }}
            {{ (p.external_requests ?? []).join(', ') }}</span
          >
        </li>
      </ul>
    </div>
    <q-btn
      class="q-mt-md"
      color="primary"
      no-caps
      icon="r_add"
      :label="t('dashboard.newMap')"
      to="/maps"
    />
  </div>
  <q-spinner v-else color="primary" size="2em" />
</template>

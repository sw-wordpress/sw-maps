<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig, hasCapability } from '@/config';
import { useMapsStore } from '@/stores/maps';
import { useProvidersStore } from '@/stores/providers';
import { useNotify } from '@/composables/useNotify';
import { errorMessage } from '@/utils/errors';

const { t } = useI18n();
const maps = useMapsStore();
const providers = useProvidersStore();
const notify = useNotify();
const canManage = hasCapability(getConfig(), 'swmap_manage_system');
const ready = ref(false);

const published = computed(() => maps.items.filter((m) => m.status === 'published').length);
const features = computed(() => maps.items.reduce((sum, m) => sum + (m.feature_count ?? 0), 0));

onMounted(async () => {
  maps.search = '';
  maps.status = '';
  maps.page = 1;
  maps.perPage = 100;
  try {
    await Promise.all([maps.load(), canManage ? providers.load() : Promise.resolve()]);
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
        <div class="swmap-stat__value" data-test="stat-maps">{{ maps.total }}</div>
        <div class="swmap-stat__label">{{ t('dashboard.maps') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-published">{{ published }}</div>
        <div class="swmap-stat__label">{{ t('dashboard.published') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-drafts">
          {{ maps.items.length - published }}
        </div>
        <div class="swmap-stat__label">{{ t('dashboard.drafts') }}</div>
      </div>
      <div class="swmap-stat">
        <div class="swmap-stat__value" data-test="stat-features">{{ features }}</div>
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

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig, hasCapability } from '@/config';

const { t } = useI18n();
const config = getConfig();
const canManage = computed(() => hasCapability(config, 'swmap_manage_system'));

const tabs = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: 'dashboard' },
  { to: '/maps', label: t('nav.maps'), icon: 'map' },
  ...(canManage.value
    ? [
        { to: '/providers', label: t('nav.providers'), icon: 'layers' },
        { to: '/settings', label: t('nav.settings'), icon: 'settings' },
        { to: '/status', label: t('nav.status'), icon: 'monitor_heart' },
      ]
    : []),
]);
</script>

<template>
  <div class="swmap-app">
    <nav class="swmap-nav" :aria-label="t('app.title')">
      <q-tabs
        dense
        align="left"
        no-caps
        inline-label
        active-color="primary"
        indicator-color="primary"
      >
        <q-route-tab
          v-for="tab in tabs"
          :key="tab.to"
          :to="tab.to"
          :icon="tab.icon"
          :label="tab.label"
          exact
        />
      </q-tabs>
    </nav>
    <main class="swmap-main">
      <router-view v-slot="{ Component }">
        <suspense>
          <component :is="Component" />
          <template #fallback>
            <q-spinner color="primary" size="2em" :aria-label="t('app.loading')" />
          </template>
        </suspense>
      </router-view>
    </main>
  </div>
</template>

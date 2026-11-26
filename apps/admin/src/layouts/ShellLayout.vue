<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig, hasCapability } from '@/config';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';

const { t } = useI18n();
const app = useAppStore();
const config = getConfig();
const main = ref<HTMLElement | null>(null);
const navItems = computed(() => NAV_ITEMS.filter((i) => !i.cap || hasCapability(config, i.cap)));

function focusMain(): void {
  main.value?.focus();
}
</script>

<template>
  <div class="swmap-shell" :class="{ 'swmap-shell--collapsed': app.railCollapsed }">
    <a href="#swmap-main" class="swmap-skip" @click.prevent="focusMain">{{
      t('app.skipToContent')
    }}</a>

    <aside class="swmap-rail">
      <div class="swmap-rail__brand">
        <span class="swmap-rail__logo" aria-hidden="true"><q-icon name="r_map" size="22px" /></span>
        <span class="swmap-rail__name">
          <strong>SW Maps</strong>
          <small v-if="config.version" class="swmap-num">v{{ config.version }}</small>
        </span>
      </div>

      <nav class="swmap-rail__nav" :aria-label="t('app.mainNavigation')">
        <ul>
          <li v-for="item in navItems" :key="item.name">
            <router-link
              :to="item.to"
              class="swmap-nav-link"
              :active-class="item.to === '/' ? '' : 'swmap-nav-link--active'"
              exact-active-class="swmap-nav-link--active"
            >
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swmap-nav-link__label">{{ t(item.labelKey) }}</span>
              <q-tooltip
                v-if="app.railCollapsed"
                anchor="center right"
                self="center left"
                :offset="[8, 0]"
                >{{ t(item.labelKey) }}</q-tooltip
              >
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swmap-rail__tools">
        <q-btn
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="app.isDark ? t('app.switchToLight') : t('app.switchToDark')"
          :aria-pressed="app.isDark"
          @click="app.toggleTheme()"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swmap-rail__collapse"
          flat
          round
          dense
          :icon="
            app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'
          "
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="!app.railCollapsed"
          @click="app.railCollapsed = !app.railCollapsed"
        />
      </div>
    </aside>

    <main id="swmap-main" ref="main" class="swmap-main" tabindex="-1">
      <router-view v-slot="{ Component }">
        <transition name="swmap-fade" mode="out-in">
          <component :is="Component" :key="String($route.name ?? '')" />
        </transition>
      </router-view>
    </main>

    <div class="swmap-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {{ app.announcement }}
    </div>
  </div>
</template>

<style scoped>
.swmap-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swmap-bg);
  position: relative;
}
.swmap-shell--collapsed {
  --rail-w: 76px;
}
.swmap-skip {
  position: absolute;
  left: 12px;
  top: -60px;
  z-index: 100;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swmap-primary);
  color: #fff !important;
  font-weight: 600;
}
.swmap-skip:focus {
  top: 10px;
}
.swmap-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swmap-rail-bg);
  border-right: 1px solid var(--swmap-border);
  transition: width 0.2s ease;
}
.swmap-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swmap-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swmap-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swmap-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swmap-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swmap-rail__name small {
  color: var(--swmap-muted);
  font-size: 12px;
}
.swmap-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swmap-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swmap-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swmap-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swmap-nav-link:hover {
  background: var(--swmap-surface-3);
  color: var(--swmap-text) !important;
}
.swmap-nav-link--active {
  background: var(--swmap-primary-bg);
  color: var(--swmap-primary-text) !important;
}
.swmap-nav-link--active::before {
  content: '';
  position: absolute;
  left: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--swmap-gradient);
}
.swmap-nav-link__badge {
  margin-left: auto;
  min-width: 22px;
  padding: 0 7px;
  border-radius: 999px;
  text-align: center;
  font-size: 12px;
  line-height: 22px;
  color: #fff;
  background: var(--swmap-gradient);
}
.swmap-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swmap-border);
  color: var(--swmap-muted);
}
.swmap-shell--collapsed .swmap-rail__name,
.swmap-shell--collapsed .swmap-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swmap-shell--collapsed .swmap-nav-link {
  justify-content: center;
}
.swmap-shell--collapsed .swmap-nav-link__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  margin: 0;
  min-width: 18px;
  line-height: 18px;
  font-size: 11px;
  padding: 0 5px;
}
.swmap-shell--collapsed .swmap-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swmap-shell--collapsed .swmap-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swmap-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swmap-main > * {
  max-width: 1320px;
  margin-inline: auto;
}

@media (max-width: 860px) {
  .swmap-shell,
  .swmap-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swmap-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-right: 0;
    border-bottom: 1px solid var(--swmap-border);
  }
  .swmap-rail__brand {
    padding: 0;
  }
  .swmap-rail__name {
    display: none;
  }
  .swmap-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swmap-rail__nav ul {
    flex-direction: row;
  }
  .swmap-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swmap-shell--collapsed .swmap-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swmap-nav-link--active::before {
    left: 10px;
    right: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swmap-nav-link__badge {
    position: absolute;
    top: 0;
    right: 2px;
    margin: 0;
    min-width: 18px;
    line-height: 18px;
    font-size: 11px;
    padding: 0 5px;
  }
  .swmap-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swmap-rail__collapse {
    display: none;
  }
  .swmap-main {
    padding-top: 20px;
  }
}
</style>

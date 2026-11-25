import { createRouter, createWebHashHistory } from 'vue-router';
import type { Router } from 'vue-router';
import { useEditorStore } from '@/stores/editor';

/**
 * Lazy view loader that retries: a chunk request can fail transiently (network change, busy server) and would leave the
 * router view blank until the page is reloaded.
 */
export function retryImport<T>(
  load: () => Promise<T>,
  attempts = 3,
  delayMs = 400,
): () => Promise<T> {
  return async () => {
    for (let attempt = 1; ; attempt++) {
      try {
        return await load();
      } catch (error) {
        if (attempt >= attempts) throw error;
        await new Promise((resolve) => setTimeout(resolve, delayMs * attempt));
      }
    }
  };
}

export const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: retryImport(() => import('@/features/dashboard/DashboardView.vue')),
  },
  {
    path: '/maps',
    name: 'maps',
    component: retryImport(() => import('@/features/maps/MapsView.vue')),
  },
  {
    path: '/maps/:id(\\d+)',
    name: 'editor',
    component: retryImport(() => import('@/features/editor/EditorView.vue')),
    props: true,
  },
  {
    path: '/providers',
    name: 'providers',
    component: retryImport(() => import('@/features/providers/ProvidersView.vue')),
  },
  {
    path: '/settings',
    name: 'settings',
    component: retryImport(() => import('@/features/settings/SettingsView.vue')),
  },
  {
    path: '/status',
    name: 'status',
    component: retryImport(() => import('@/features/status/StatusView.vue')),
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

export function createAppRouter(
  confirmLeave: (message: string) => boolean = (m) => window.confirm(m),
): Router {
  const router = createRouter({ history: createWebHashHistory(), routes });
  router.beforeEach((to, from) => {
    if (from.name === 'editor' && to.fullPath !== from.fullPath) {
      const editor = useEditorStore();
      if (editor.dirty && !confirmLeave(unsavedMessage)) return false;
    }
    return true;
  });
  return router;
}

let unsavedMessage = 'There are unsaved changes. Leave without saving?';

/** The editor view registers the translated message once it is mounted. */
export function setUnsavedMessage(message: string): void {
  unsavedMessage = message;
}

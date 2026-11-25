import { createRouter, createWebHashHistory } from 'vue-router';
import type { Router } from 'vue-router';
import { useEditorStore } from '@/stores/editor';

export const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/features/dashboard/DashboardView.vue'),
  },
  { path: '/maps', name: 'maps', component: () => import('@/features/maps/MapsView.vue') },
  {
    path: '/maps/:id(\\d+)',
    name: 'editor',
    component: () => import('@/features/editor/EditorView.vue'),
    props: true,
  },
  {
    path: '/providers',
    name: 'providers',
    component: () => import('@/features/providers/ProvidersView.vue'),
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/features/settings/SettingsView.vue'),
  },
  { path: '/status', name: 'status', component: () => import('@/features/status/StatusView.vue') },
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

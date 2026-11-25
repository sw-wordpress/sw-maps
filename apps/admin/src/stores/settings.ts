import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { SettingsData, StatusData } from '@/api';
import { api } from '@/api';

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref<SettingsData | null>(null);
  const status = ref<StatusData | null>(null);
  const loading = ref(false);

  async function load(): Promise<void> {
    loading.value = true;
    try {
      settings.value = await api().settings.get();
    } finally {
      loading.value = false;
    }
  }

  async function save(patch: Partial<SettingsData>): Promise<void> {
    settings.value = await api().settings.update(patch);
  }

  async function loadStatus(): Promise<void> {
    loading.value = true;
    try {
      status.value = await api().status.get();
    } finally {
      loading.value = false;
    }
  }

  return { settings, status, loading, load, save, loadStatus };
});

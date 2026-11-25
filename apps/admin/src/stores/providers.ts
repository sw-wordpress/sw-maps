import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type { ProviderData } from '@sw-maps/map-contracts';
import type { Body, ConnectionTest } from '@/api';
import { api } from '@/api';

export const useProvidersStore = defineStore('providers', () => {
  const items = ref<ProviderData[]>([]);
  const loading = ref(false);
  const tests = ref<Record<number, ConnectionTest>>({});
  const enabled = computed(() => items.value.filter((p) => p.enabled));

  async function load(): Promise<void> {
    loading.value = true;
    try {
      items.value = await api().providers.list();
    } finally {
      loading.value = false;
    }
  }

  async function save(id: number | null, body: Body): Promise<ProviderData> {
    const saved =
      id === null ? await api().providers.create(body) : await api().providers.update(id, body);
    await load();
    return saved;
  }

  async function setEnabled(id: number, value: boolean): Promise<void> {
    await api().providers.update(id, { enabled: value });
    await load();
  }

  async function remove(id: number): Promise<void> {
    await api().providers.remove(id);
    await load();
  }

  async function test(id: number): Promise<ConnectionTest> {
    const result = await api().providers.test(id);
    tests.value = { ...tests.value, [id]: result };
    return result;
  }

  return { items, loading, tests, enabled, load, save, setEnabled, remove, test };
});

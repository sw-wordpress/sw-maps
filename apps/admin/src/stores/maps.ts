import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { MapData } from '@sw-maps/map-contracts';
import { api } from '@/api';

export const useMapsStore = defineStore('maps', () => {
  const items = ref<MapData[]>([]);
  const total = ref(0);
  const totalPages = ref(1);
  const loading = ref(false);
  const search = ref('');
  const status = ref<'' | 'draft' | 'published'>('');
  const page = ref(1);
  const perPage = ref(20);
  let request = 0;

  async function load(): Promise<void> {
    const ticket = ++request;
    loading.value = true;
    try {
      const result = await api().maps.list({
        search: search.value,
        status: status.value || undefined,
        page: page.value,
        per_page: perPage.value,
      });
      if (ticket !== request) return; // a newer request superseded this one
      const lastPage = Math.max(1, result.totalPages);
      if (page.value > lastPage) {
        // E.g. the last map of the final page was deleted: show the new last page instead of an empty table.
        page.value = lastPage;
        loading.value = false;
        return load();
      }
      items.value = result.items;
      total.value = result.total;
      totalPages.value = result.totalPages;
    } finally {
      if (ticket === request) loading.value = false;
    }
  }

  async function create(title: string): Promise<MapData> {
    const map = await api().maps.create({ title });
    await load();
    return map;
  }

  async function duplicate(id: number): Promise<MapData> {
    const map = await api().maps.duplicate(id);
    await load();
    return map;
  }

  async function remove(id: number): Promise<void> {
    await api().maps.remove(id);
    await load();
  }

  async function setStatus(id: number, next: 'draft' | 'published'): Promise<void> {
    await api().maps.update(id, { status: next });
    await load();
  }

  return {
    items,
    total,
    totalPages,
    loading,
    search,
    status,
    page,
    perPage,
    load,
    create,
    duplicate,
    remove,
    setStatus,
  };
});

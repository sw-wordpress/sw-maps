<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import type { MapData } from '@sw-maps/map-contracts';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useNotify } from '@/composables/useNotify';
import { getConfig, hasCapability } from '@/config';
import { useMapsStore } from '@/stores/maps';
import { errorMessage } from '@/utils/errors';
import EmbedDialog from './EmbedDialog.vue';

const { t } = useI18n();
const maps = useMapsStore();
const router = useRouter();
const notify = useNotify();
const canPublish = hasCapability(getConfig(), 'swmap_publish_maps');

const newTitle = ref('');
const creating = ref(false);
const deleting = ref<MapData | null>(null);
const confirmOpen = ref(false);
const embedFor = ref<number | null>(null);
const embedOpen = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const statusOptions = computed(() => [
  { value: '', label: t('common.all') },
  { value: 'draft', label: t('common.draft') },
  { value: 'published', label: t('common.published') },
]);

async function run(action: () => Promise<unknown>, success?: string): Promise<void> {
  try {
    await action();
    if (success) notify.success(success);
  } catch (error) {
    notify.error(errorMessage(error, t('app.saveFailed')));
  }
}

onMounted(() => {
  maps.perPage = 20;
  void run(() => maps.load());
});

watch(
  () => maps.search,
  () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      maps.page = 1;
      void run(() => maps.load());
    }, 300);
  },
);
watch([() => maps.status, () => maps.page], () => void run(() => maps.load()));

async function create(): Promise<void> {
  const title = newTitle.value.trim();
  if (title === '') return;
  creating.value = true;
  try {
    const map = await maps.create(title);
    newTitle.value = '';
    notify.success(t('maps.created'));
    await router.push({ name: 'editor', params: { id: map.id } });
  } catch (error) {
    notify.error(errorMessage(error, t('app.saveFailed')));
  } finally {
    creating.value = false;
  }
}

function askDelete(map: MapData): void {
  deleting.value = map;
  confirmOpen.value = true;
}

function confirmDelete(): void {
  const map = deleting.value;
  if (map) void run(() => maps.remove(map.id), t('maps.deleted'));
}

function openEmbed(map: MapData): void {
  embedFor.value = map.id;
  embedOpen.value = true;
}

const columns = computed(() => [
  { name: 'title', label: t('maps.title'), field: 'title', align: 'left' as const },
  { name: 'status', label: t('common.status'), field: 'status', align: 'left' as const },
  { name: 'layers', label: t('maps.layers'), field: 'layer_count', align: 'right' as const },
  { name: 'features', label: t('maps.features'), field: 'feature_count', align: 'right' as const },
  { name: 'actions', label: t('common.actions'), field: 'id', align: 'right' as const },
]);
</script>

<template>
  <div>
    <div class="swmap-toolbar">
      <q-input
        v-model="maps.search"
        dense
        outlined
        clearable
        debounce="0"
        :label="t('common.search')"
        style="min-width: 220px"
        data-test="search"
      >
        <template #prepend><q-icon name="r_search" /></template>
      </q-input>
      <q-select
        v-model="maps.status"
        dense
        outlined
        emit-value
        map-options
        :options="statusOptions"
        :label="t('common.status')"
        style="min-width: 160px"
        data-test="status-filter"
      />
      <q-space />
      <q-input
        v-model="newTitle"
        dense
        outlined
        :label="t('maps.title')"
        style="min-width: 220px"
        data-test="new-title"
        @keyup.enter="create"
      />
      <q-btn
        color="primary"
        no-caps
        icon="r_add"
        :label="t('maps.new')"
        :loading="creating"
        :disable="newTitle.trim() === ''"
        data-test="create"
        @click="create"
      />
    </div>

    <q-table
      flat
      bordered
      row-key="id"
      :rows="maps.items"
      :columns="columns"
      :loading="maps.loading"
      hide-pagination
      :rows-per-page-options="[0]"
      :no-data-label="t('maps.empty')"
    >
      <template #body-cell-title="p">
        <q-td :props="p">
          <router-link :to="{ name: 'editor', params: { id: p.row.id } }" data-test="open">{{
            p.row.title || t('maps.untitled')
          }}</router-link>
        </q-td>
      </template>
      <template #body-cell-status="p">
        <q-td :props="p">
          <q-badge
            :color="p.row.status === 'published' ? 'positive' : 'grey-7'"
            :label="p.row.status === 'published' ? t('common.published') : t('common.draft')"
          />
        </q-td>
      </template>
      <template #body-cell-actions="p">
        <q-td :props="p" auto-width>
          <q-btn
            flat
            dense
            round
            icon="r_edit"
            :aria-label="t('common.edit')"
            :to="{ name: 'editor', params: { id: p.row.id } }"
          />
          <q-btn
            flat
            dense
            round
            icon="r_code"
            :aria-label="t('maps.embed')"
            data-test="embed"
            @click="openEmbed(p.row)"
          />
          <q-btn
            flat
            dense
            round
            icon="r_content_copy"
            :aria-label="t('maps.duplicate')"
            data-test="duplicate"
            @click="run(() => maps.duplicate(p.row.id), t('maps.duplicated'))"
          />
          <q-btn
            v-if="canPublish"
            flat
            dense
            round
            :icon="p.row.status === 'published' ? 'r_visibility_off' : 'r_public'"
            :aria-label="p.row.status === 'published' ? t('maps.unpublish') : t('maps.publish')"
            data-test="toggle-publish"
            @click="
              run(
                () =>
                  maps.setStatus(p.row.id, p.row.status === 'published' ? 'draft' : 'published'),
                p.row.status === 'published' ? t('maps.unpublishedNow') : t('maps.publishedNow'),
              )
            "
          />
          <q-btn
            flat
            dense
            round
            icon="r_delete"
            color="negative"
            :aria-label="t('common.delete')"
            data-test="delete"
            @click="askDelete(p.row)"
          />
        </q-td>
      </template>
    </q-table>

    <div v-if="maps.totalPages > 1" class="flex flex-center q-mt-md">
      <q-pagination
        v-model="maps.page"
        :max="maps.totalPages"
        direction-links
        data-test="pagination"
      />
    </div>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('maps.deleteTitle')"
      :message="t('maps.deleteConfirm', { title: deleting?.title ?? '' })"
      @confirm="confirmDelete"
    />
    <EmbedDialog v-model="embedOpen" :map-id="embedFor" />
  </div>
</template>

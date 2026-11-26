<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CategoryData } from '@sw-maps/map-contracts';
import { safeColor } from '@sw-maps/map-contracts';
import { useNotify } from '@/composables/useNotify';
import { errorMessage } from '@/utils/errors';
import { FEATURE_ICONS } from './icons';

const props = defineProps<{
  modelValue: boolean;
  categories: CategoryData[];
  save: (id: number | null, body: Record<string, unknown>) => Promise<void>;
  remove: (id: number) => Promise<void>;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const { t } = useI18n();
const notify = useNotify();

const editing = ref<number | 'new' | null>(null);
const busy = ref(false);
const form = reactive({ title: '', color: '#2563eb', icon: 'builtin:pin' });
const iconOptions = computed(() =>
  FEATURE_ICONS.map((name) => ({
    value: `builtin:${name}`,
    label: t(`feature.icons.${name}` as 'feature.icons.pin'),
  })),
);

function edit(category: CategoryData | null): void {
  editing.value = category?.id ?? 'new';
  form.title = category?.title ?? '';
  form.color = safeColor(category?.style['color'], '#2563eb');
  const icon = category?.style['icon'];
  form.icon = typeof icon === 'string' && icon !== '' ? icon : 'builtin:pin';
}

async function run(action: () => Promise<void>, success: string): Promise<void> {
  busy.value = true;
  try {
    await action();
    notify.success(success);
    editing.value = null;
  } catch (error) {
    notify.error(errorMessage(error, t('app.saveFailed')));
  } finally {
    busy.value = false;
  }
}

function submit(): void {
  const id = editing.value === 'new' ? null : editing.value;
  if (form.title.trim() === '') return;
  void run(
    () =>
      props.save(id, { title: form.title.trim(), style: { color: form.color, icon: form.icon } }),
    t('categories.saved'),
  );
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="width: min(520px, 94vw)" data-test="category-dialog">
      <q-card-section>
        <div class="text-h6">{{ t('categories.title') }}</div>
      </q-card-section>
      <q-card-section>
        <p v-if="categories.length === 0" class="swmap-muted" data-test="categories-empty">
          {{ t('categories.empty') }}
        </p>
        <q-list v-else separator>
          <q-item v-for="c in categories" :key="c.id" data-test="category">
            <q-item-section avatar>
              <span
                class="swmap-swatch"
                :style="{ background: safeColor(c.style['color'], '#2563eb') }"
              />
            </q-item-section>
            <q-item-section>{{ c.title }}</q-item-section>
            <q-item-section side>
              <div>
                <q-btn
                  flat
                  dense
                  round
                  icon="r_edit"
                  :aria-label="t('common.edit')"
                  data-test="category-edit"
                  @click="edit(c)"
                />
                <q-btn
                  flat
                  dense
                  round
                  icon="r_delete"
                  color="negative"
                  :aria-label="t('common.delete')"
                  data-test="category-delete"
                  @click="run(() => props.remove(c.id), t('categories.deleted'))"
                />
              </div>
            </q-item-section>
          </q-item>
        </q-list>
        <div v-if="editing !== null" class="q-mt-md" data-test="category-form">
          <q-input
            v-model="form.title"
            outlined
            dense
            :label="t('categories.name')"
            class="swmap-field"
            data-test="category-title"
            @keyup.enter="submit"
          />
          <q-select
            v-model="form.icon"
            outlined
            dense
            emit-value
            map-options
            :options="iconOptions"
            :label="t('feature.icon')"
            class="swmap-field"
            data-test="category-icon"
          />
          <div class="swmap-field">
            <div class="text-caption">{{ t('feature.color') }}</div>
            <input
              v-model="form.color"
              type="color"
              :aria-label="t('feature.color')"
              data-test="category-color"
            />
          </div>
          <q-btn
            color="primary"
            no-caps
            :label="t('common.save')"
            :loading="busy"
            :disable="form.title.trim() === ''"
            data-test="category-save"
            @click="submit"
          />
        </div>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn
          flat
          no-caps
          icon="r_add"
          :label="t('categories.add')"
          data-test="category-add"
          @click="edit(null)"
        />
        <q-btn flat no-caps :label="t('common.close')" @click="emit('update:modelValue', false)" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swmap-swatch {
  display: inline-block;
  width: 18px;
  height: 18px;
  border-radius: 50%;
}
</style>

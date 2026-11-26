<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { EditTool } from '@sw-maps/map-contracts';

defineProps<{
  tool: EditTool;
  canUndo: boolean;
  canRedo: boolean;
  dirty: boolean;
  saving: boolean;
  mobile: boolean;
}>();
const emit = defineEmits<{
  'update:tool': [tool: EditTool];
  'update:mobile': [value: boolean];
  undo: [];
  redo: [];
  save: [];
  fit: [];
}>();
const { t } = useI18n();

const tools: Array<{
  tool: EditTool;
  icon: string;
  label: 'editor.toolSelect' | 'editor.toolPoint' | 'editor.toolLine' | 'editor.toolPolygon';
}> = [
  { tool: 'select', icon: 'r_near_me', label: 'editor.toolSelect' },
  { tool: 'point', icon: 'r_place', label: 'editor.toolPoint' },
  { tool: 'line', icon: 'r_timeline', label: 'editor.toolLine' },
  { tool: 'polygon', icon: 'r_pentagon', label: 'editor.toolPolygon' },
];
</script>

<template>
  <div class="swmap-toolbar swmap-editor__bar" role="toolbar" :aria-label="t('editor.tools')">
    <q-btn-group flat>
      <q-btn
        v-for="item in tools"
        :key="item.tool"
        dense
        no-caps
        :flat="tool !== item.tool"
        :color="tool === item.tool ? 'primary' : undefined"
        :icon="item.icon"
        :aria-label="t(item.label)"
        :aria-pressed="tool === item.tool"
        :data-test="`tool-${item.tool}`"
        @click="emit('update:tool', item.tool)"
      >
        <q-tooltip>{{ t(item.label) }}</q-tooltip>
      </q-btn>
    </q-btn-group>
    <q-btn
      flat
      dense
      icon="r_undo"
      :aria-label="t('editor.undo')"
      :disable="!canUndo"
      data-test="undo"
      @click="emit('undo')"
    />
    <q-btn
      flat
      dense
      icon="r_redo"
      :aria-label="t('editor.redo')"
      :disable="!canRedo"
      data-test="redo"
      @click="emit('redo')"
    />
    <q-btn
      flat
      dense
      no-caps
      icon="r_zoom_out_map"
      :label="t('editor.fitAll')"
      data-test="fit"
      @click="emit('fit')"
    />
    <q-btn-toggle
      :model-value="mobile"
      dense
      no-caps
      flat
      toggle-color="primary"
      :options="[
        { value: false, icon: 'r_desktop_windows', attrs: { 'aria-label': t('editor.desktop') } },
        { value: true, icon: 'r_smartphone', attrs: { 'aria-label': t('editor.mobile') } },
      ]"
      data-test="preview-toggle"
      @update:model-value="emit('update:mobile', $event)"
    />
    <q-space />
    <span v-if="dirty" class="swmap-muted" data-test="dirty">{{ t('editor.unsaved') }}</span>
    <q-btn
      color="primary"
      no-caps
      icon="r_save"
      :label="t('editor.save')"
      :disable="!dirty"
      :loading="saving"
      data-test="save"
      @click="emit('save')"
    />
  </div>
</template>

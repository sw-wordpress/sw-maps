<script setup lang="ts">
import { computed } from 'vue';

export type ChipKind =
  'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'running' | 'primary';
interface Meta {
  kind: ChipKind;
  icon: string;
}

const props = withDefaults(
  defineProps<{
    kind?: ChipKind;
    icon?: string;
    label?: string;
    dense?: boolean;
  }>(),
  {
    kind: undefined,
    icon: undefined,
    label: undefined,
    dense: false,
  },
);

const meta = computed<Meta>(() => ({
  kind: props.kind ?? 'neutral',
  icon: props.icon ?? 'r_circle',
}));
</script>

<template>
  <span class="swmap-chip" :class="[`swmap-chip--${meta.kind}`, { 'swmap-chip--dense': dense }]">
    <q-icon :name="meta.icon" class="swmap-chip__icon" size="1.15em" aria-hidden="true" />
    <span class="swmap-chip__text">{{ label }}</span>
  </span>
</template>

<style scoped>
.swmap-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  max-width: 100%;
  background: var(--swmap-neutral-bg);
  color: var(--swmap-neutral-text);
  border: 1px solid transparent;
}
.swmap-chip--dense {
  font-size: 12px;
  padding: 1px 8px 1px 6px;
}
.swmap-chip__text {
  overflow: hidden;
  text-overflow: ellipsis;
}
.swmap-chip--success {
  background: var(--swmap-success-bg);
  color: var(--swmap-success-text);
}
.swmap-chip--warning {
  background: var(--swmap-warning-bg);
  color: var(--swmap-warning-text);
}
.swmap-chip--danger {
  background: var(--swmap-danger-bg);
  color: var(--swmap-danger-text);
}
.swmap-chip--info {
  background: var(--swmap-info-bg);
  color: var(--swmap-info-text);
}
.swmap-chip--primary,
.swmap-chip--running {
  background: var(--swmap-primary-bg);
  color: var(--swmap-primary-text);
}
.swmap-chip--running .swmap-chip__icon {
  animation: swmap-spin 1.6s linear infinite;
}
</style>

<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string;
    value?: string;
    hint?: string;
    icon?: string;
    loading?: boolean;
    tone?: 'default' | 'success' | 'warning' | 'danger';
  }>(),
  { value: undefined, hint: undefined, icon: undefined, loading: false, tone: 'default' },
);
</script>

<template>
  <div class="swmap-stat" :class="`swmap-stat--${tone}`">
    <div class="swmap-stat__top">
      <span class="swmap-stat__label">{{ label }}</span>
      <q-icon v-if="icon" :name="icon" size="20px" class="swmap-stat__icon" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <q-skeleton type="text" width="60%" height="32px" />
      <q-skeleton type="text" width="40%" />
    </template>
    <template v-else>
      <div class="swmap-stat__value swmap-num">
        <slot>{{ value ?? '–' }}</slot>
      </div>
      <div v-if="hint || $slots.hint" class="swmap-stat__hint">
        <slot name="hint">{{ hint }}</slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
.swmap-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  background: var(--swmap-surface);
  border: 1px solid var(--swmap-border);
  border-radius: var(--swmap-radius);
  box-shadow: var(--swmap-shadow-1);
  min-width: 0;
}
.swmap-stat__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swmap-stat__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--swmap-muted);
}
.swmap-stat__icon {
  color: var(--swmap-primary);
}
.swmap-stat__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.swmap-stat__hint {
  font-size: 13px;
  color: var(--swmap-muted);
}
.swmap-stat--success .swmap-stat__icon {
  color: var(--swmap-success);
}
.swmap-stat--warning .swmap-stat__icon {
  color: var(--swmap-warning);
}
.swmap-stat--danger .swmap-stat__icon {
  color: var(--swmap-danger);
}
</style>

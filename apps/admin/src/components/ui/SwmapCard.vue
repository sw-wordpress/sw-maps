<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    icon?: string;
    padding?: boolean;
    interactive?: boolean;
    tag?: string;
    accent?: boolean;
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    icon: undefined,
    padding: true,
    interactive: false,
    tag: 'section',
    accent: false,
  },
);
</script>

<template>
  <component
    :is="tag"
    class="swmap-card"
    :class="{ 'swmap-card--interactive': interactive, 'swmap-card--accent': accent }"
  >
    <header v-if="title || $slots.header || $slots.actions" class="swmap-card__header">
      <slot name="header">
        <div class="swmap-card__heading">
          <span v-if="icon" class="swmap-card__icon" aria-hidden="true"
            ><q-icon :name="icon" size="20px"
          /></span>
          <div class="swmap-card__titles">
            <h2 class="swmap-card__title">{{ title }}</h2>
            <p v-if="subtitle" class="swmap-card__subtitle">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <div v-if="$slots.actions" class="swmap-card__actions"><slot name="actions" /></div>
    </header>
    <div class="swmap-card__body" :class="{ 'swmap-card__body--flush': !padding }"><slot /></div>
    <footer v-if="$slots.footer" class="swmap-card__footer"><slot name="footer" /></footer>
  </component>
</template>

<style scoped>
.swmap-card {
  position: relative;
  background: var(--swmap-surface);
  border: 1px solid var(--swmap-border);
  border-radius: var(--swmap-radius);
  box-shadow: var(--swmap-shadow-1);
  min-width: 0;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease,
    border-color 0.2s ease;
}
.swmap-card--interactive:hover {
  box-shadow: var(--swmap-shadow-2);
  transform: translateY(-1px);
}
.swmap-card--accent::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  border-radius: var(--swmap-radius) var(--swmap-radius) 0 0;
  background: var(--swmap-gradient);
}
.swmap-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 18px 22px 0;
}
.swmap-card__heading {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.swmap-card__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  color: var(--swmap-primary);
  background: var(--swmap-gradient-soft);
}
.swmap-card__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
}
.swmap-card__subtitle {
  font-size: 13px;
  color: var(--swmap-muted);
}
.swmap-card__actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.swmap-card__body {
  padding: 18px 22px 22px;
}
.swmap-card__body--flush {
  padding: 12px 0 0;
}
.swmap-card__footer {
  padding: 14px 22px;
  border-top: 1px solid var(--swmap-border);
}
@media (max-width: 520px) {
  .swmap-card__header {
    padding: 16px 16px 0;
  }
  .swmap-card__body {
    padding: 14px 16px 18px;
  }
}
</style>

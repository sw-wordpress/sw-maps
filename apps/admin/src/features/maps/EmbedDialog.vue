<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';

const props = defineProps<{ modelValue: boolean; mapId: number | null }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const { t } = useI18n();
const notify = useNotify();

const shortcode = computed(() => `[sw_map id="${props.mapId ?? 0}"]`);
const php = computed(() => `<?php echo swmap_render_map( ${props.mapId ?? 0} ); ?>`);

async function copy(text: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text);
    notify.success(t('common.copied'));
  } catch {
    // Clipboard access can be blocked; the text stays selectable.
  }
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="min-width: min(520px, 92vw)">
      <q-card-section>
        <div class="text-h6">{{ t('embed.title') }}</div>
      </q-card-section>
      <q-card-section>
        <div class="text-subtitle2">{{ t('embed.shortcode') }}</div>
        <div class="swmap-code" data-test="shortcode">{{ shortcode }}</div>
        <q-btn
          flat
          dense
          no-caps
          icon="r_content_copy"
          :label="t('common.copy')"
          data-test="copy-shortcode"
          @click="copy(shortcode)"
        />
        <div class="text-subtitle2 q-mt-md">{{ t('embed.block') }}</div>
        <p>{{ t('embed.blockText') }}</p>
        <div class="text-subtitle2">{{ t('embed.php') }}</div>
        <div class="swmap-code" data-test="php">{{ php }}</div>
        <q-btn
          flat
          dense
          no-caps
          icon="r_content_copy"
          :label="t('common.copy')"
          @click="copy(php)"
        />
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat no-caps :label="t('common.close')" @click="emit('update:modelValue', false)" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

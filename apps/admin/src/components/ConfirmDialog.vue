<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{
  modelValue: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  color?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [] }>();
const { t } = useI18n();
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="min-width: 320px">
      <q-card-section>
        <div class="text-h6">{{ title }}</div>
      </q-card-section>
      <q-card-section class="q-pt-none">{{ message }}</q-card-section>
      <q-card-actions align="right">
        <q-btn flat no-caps :label="t('common.cancel')" @click="emit('update:modelValue', false)" />
        <q-btn
          :color="color ?? 'negative'"
          no-caps
          data-test="confirm"
          :label="confirmLabel ?? t('common.delete')"
          @click="
            emit('confirm');
            emit('update:modelValue', false);
          "
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

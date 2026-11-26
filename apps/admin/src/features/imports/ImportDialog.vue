<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { LayerData } from '@sw-maps/map-contracts';
import { api } from '@/api';
import type { ImportIssue, ImportReport } from '@/api';
import { isApiError } from '@/api/client';
import { useNotify } from '@/composables/useNotify';
import { errorMessage } from '@/utils/errors';

const props = defineProps<{ modelValue: boolean; mapId: number; layers: LayerData[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; imported: [layerId: number] }>();
const { t, te } = useI18n();
const notify = useNotify();

type Format = 'geojson' | 'gpx' | 'csv';
const TARGETS = ['title', 'description', 'address', 'link', 'external_id'] as const;

const file = ref<File | null>(null);
const content = ref('');
const format = ref<Format>('geojson');
const delimiter = ref(',');
const decimal = ref('.');
const lonColumn = ref('');
const latColumn = ref('');
const mode = ref('new_layer');
const layerId = ref<number | null>(null);
const layerTitle = ref('');
const mapping = ref<Record<string, string>>({});
const extraColumns = ref<string[]>([]);
const skipInvalid = ref(false);
const confirmReplace = ref(false);
const report = ref<ImportReport | null>(null);
const busy = ref(false);
const failure = ref('');

const formatOptions = [
  { value: 'geojson', label: 'GeoJSON' },
  { value: 'gpx', label: 'GPX' },
  { value: 'csv', label: 'CSV' },
];
const modeOptions = computed(() => [
  { value: 'new_layer', label: t('import.modeNew') },
  { value: 'append', label: t('import.modeAppend') },
  { value: 'upsert', label: t('import.modeUpsert') },
  { value: 'replace', label: t('import.modeReplace') },
]);
const layerOptions = computed(() => props.layers.map((l) => ({ value: l.id, label: l.title })));
const columnOptions = computed(() => [
  { value: '', label: t('common.none') },
  ...(report.value?.columns ?? []).map((c) => ({ value: c, label: c })),
]);
const needsLayer = computed(() => mode.value !== 'new_layer');
const canCheck = computed(
  () => content.value !== '' && (!needsLayer.value || layerId.value !== null) && !busy.value,
);
const canImport = computed(() => {
  const r = report.value;
  if (!r || busy.value || r.imported) return false;
  if (r.created + r.updated === 0) return false;
  if (r.error_count > 0 && !skipInvalid.value) return false;
  return mode.value !== 'replace' || confirmReplace.value;
});

function reset(): void {
  file.value = null;
  content.value = '';
  report.value = null;
  failure.value = '';
  mapping.value = {};
  extraColumns.value = [];
  skipInvalid.value = false;
  confirmReplace.value = false;
  layerTitle.value = '';
  layerId.value = props.layers[0]?.id ?? null;
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) reset();
  },
);

/** The file is read in the browser and sent as text; the format is guessed from the extension. */
async function onFile(picked: File | null): Promise<void> {
  report.value = null;
  mapping.value = {};
  extraColumns.value = [];
  failure.value = '';
  if (!picked) {
    content.value = '';
    return;
  }
  const name = picked.name.toLowerCase();
  if (name.endsWith('.gpx')) format.value = 'gpx';
  else if (name.endsWith('.csv') || name.endsWith('.txt')) format.value = 'csv';
  else format.value = 'geojson';
  if (layerTitle.value === '') layerTitle.value = picked.name.replace(/\.[^.]+$/, '');
  content.value = await picked.text();
}

function body(dryRun: boolean): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    map_id: props.mapId,
    format: format.value,
    content: content.value,
    mode: mode.value,
    dry_run: dryRun,
  };
  if (needsLayer.value) payload['layer_id'] = layerId.value;
  else payload['layer_title'] = layerTitle.value;
  if (format.value === 'csv') {
    payload['options'] = {
      delimiter: delimiter.value,
      decimal: decimal.value,
      ...(lonColumn.value.trim() === '' ? {} : { lon_column: lonColumn.value.trim() }),
      ...(latColumn.value.trim() === '' ? {} : { lat_column: latColumn.value.trim() }),
    };
  }
  if (Object.keys(mapping.value).length > 0) payload['mapping'] = mapping.value;
  if (extraColumns.value.length > 0) payload['extra_columns'] = extraColumns.value;
  if (!dryRun) {
    payload['skip_invalid'] = skipInvalid.value;
    payload['confirm_replace'] = confirmReplace.value;
  }
  return payload;
}

/** Translated text of an import issue; unknown codes fall back to the server message. */
function issueText(issue: ImportIssue): string {
  const key = `import.issue.${issue.code}`;
  return te(key) ? t(key, issue.params) : issue.message;
}

function describe(error: unknown): string {
  if (isApiError(error) && error.reason) return t(`import.reason.${error.reason}`);
  return errorMessage(error, t('import.failed'));
}

async function check(keepMapping = false): Promise<void> {
  busy.value = true;
  failure.value = '';
  // A result belongs to the inputs it was requested for: when they changed meanwhile it is dropped.
  const requested = JSON.stringify(body(true));
  try {
    const result = await api().imports.run(body(true));
    if (requested !== JSON.stringify(body(true))) return;
    report.value = result;
    if (!keepMapping) mapping.value = { ...result.mapping };
  } catch (error) {
    if (requested !== JSON.stringify(body(true))) return;
    report.value = null;
    failure.value = describe(error);
  } finally {
    busy.value = false;
  }
}

function setMapping(target: string, source: string): void {
  mapping.value = { ...mapping.value, [target]: source };
  void check(true);
}

async function run(): Promise<void> {
  busy.value = true;
  failure.value = '';
  try {
    const result = await api().imports.run(body(false));
    report.value = result;
    notify.success(t('import.done', { n: result.created + result.updated }));
    if (result.layer_id !== null) emit('imported', result.layer_id);
  } catch (error) {
    failure.value = describe(error);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card style="width: min(720px, 94vw)" data-test="import-dialog">
      <q-card-section>
        <div class="text-h6">{{ t('import.title') }}</div>
      </q-card-section>
      <q-card-section class="q-gutter-md">
        <q-file
          :model-value="file"
          outlined
          dense
          accept=".geojson,.json,.gpx,.csv,.txt"
          :label="t('import.file')"
          data-test="file"
          @update:model-value="
            (f: File | null) => {
              file = f;
              void onFile(f);
            }
          "
        />
        <div class="row q-col-gutter-md">
          <q-select
            v-model="format"
            class="col-12 col-sm-4"
            dense
            outlined
            emit-value
            map-options
            :options="formatOptions"
            :label="t('import.format')"
            data-test="format"
            @update:model-value="report = null"
          />
          <template v-if="format === 'csv'">
            <q-select
              v-model="delimiter"
              class="col-6 col-sm-4"
              dense
              outlined
              :options="[',', ';', '\t', '|']"
              :label="t('import.delimiter')"
              data-test="delimiter"
              @update:model-value="report = null"
            />
            <q-select
              v-model="decimal"
              class="col-6 col-sm-4"
              dense
              outlined
              :options="['.', ',']"
              :label="t('import.decimal')"
              data-test="decimal"
              @update:model-value="report = null"
            />
          </template>
        </div>
        <div v-if="format === 'csv'" class="row q-col-gutter-md">
          <q-input
            v-model="lonColumn"
            class="col-6"
            dense
            outlined
            :label="t('import.lonColumn')"
            data-test="lon-column"
            @update:model-value="report = null"
          />
          <q-input
            v-model="latColumn"
            class="col-6"
            dense
            outlined
            :label="t('import.latColumn')"
            data-test="lat-column"
            @update:model-value="report = null"
          />
        </div>
        <div class="row q-col-gutter-md">
          <q-select
            v-model="mode"
            class="col-12 col-sm-6"
            dense
            outlined
            emit-value
            map-options
            :options="modeOptions"
            :label="t('import.mode')"
            data-test="mode"
            @update:model-value="report = null"
          />
          <q-select
            v-if="needsLayer"
            v-model="layerId"
            class="col-12 col-sm-6"
            dense
            outlined
            emit-value
            map-options
            :options="layerOptions"
            :label="t('import.targetLayer')"
            data-test="layer"
            @update:model-value="report = null"
          />
          <q-input
            v-else
            v-model="layerTitle"
            class="col-12 col-sm-6"
            dense
            outlined
            :label="t('import.layerName')"
            data-test="layer-title"
          />
        </div>

        <q-banner v-if="failure" class="bg-negative text-white" rounded dense data-test="failure">
          {{ failure }}
        </q-banner>

        <template v-if="report">
          <div class="row q-gutter-sm" data-test="summary">
            <q-chip dense>{{ t('import.created', { n: report.created }) }}</q-chip>
            <q-chip v-if="report.updated" dense>{{
              t('import.updated', { n: report.updated })
            }}</q-chip>
            <q-chip v-if="report.error_count" dense color="negative" text-color="white">
              {{ t('import.invalid', { n: report.error_count }) }}
            </q-chip>
          </div>

          <div v-if="report.columns.length > 0" data-test="mapping">
            <div class="text-subtitle2 q-mb-sm">{{ t('import.mapping') }}</div>
            <div class="row q-col-gutter-sm">
              <q-select
                v-for="target in TARGETS"
                :key="target"
                class="col-12 col-sm-6"
                dense
                outlined
                emit-value
                map-options
                :model-value="mapping[target] ?? ''"
                :options="columnOptions"
                :label="t(`import.target.${target}`)"
                :data-test="`map-${target}`"
                @update:model-value="(v: string) => setMapping(target, v)"
              />
            </div>
          </div>

          <q-select
            v-if="report.columns.length > 0"
            v-model="extraColumns"
            multiple
            use-chips
            dense
            outlined
            :options="report.columns"
            :label="t('import.extraColumns')"
            data-test="extra-columns"
            @update:model-value="check(true)"
          />
          <ul v-if="report.preview.length > 0" class="swmap-import-list" data-test="preview">
            <li v-for="(row, i) in report.preview" :key="i">
              {{ row.title || t('editor.untitledFeature') }}
              <span class="swmap-muted">· {{ t(`feature.type.${row.type}`) }}</span>
            </li>
          </ul>

          <div v-if="report.errors.length > 0" data-test="errors">
            <div class="text-subtitle2">{{ t('import.errors') }}</div>
            <ul class="swmap-import-list">
              <li v-for="(e, i) in report.errors" :key="i">#{{ e.index }}: {{ issueText(e) }}</li>
            </ul>
            <q-checkbox
              v-model="skipInvalid"
              :label="t('import.skipInvalid')"
              data-test="skip-invalid"
            />
          </div>
          <div v-if="report.warnings.length > 0" data-test="warnings">
            <div class="text-subtitle2">{{ t('import.warnings') }}</div>
            <ul class="swmap-import-list">
              <li v-for="(w, i) in report.warnings" :key="i">#{{ w.index }}: {{ issueText(w) }}</li>
            </ul>
          </div>
          <q-banner v-if="report.media_relink_hint" rounded dense data-test="media-hint">
            {{ t('import.mediaHint') }}
          </q-banner>
          <q-checkbox
            v-if="mode === 'replace'"
            v-model="confirmReplace"
            color="negative"
            :label="t('import.confirmReplace')"
            data-test="confirm-replace"
          />
        </template>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat no-caps :label="t('common.close')" @click="emit('update:modelValue', false)" />
        <q-btn
          outline
          no-caps
          :label="t('import.check')"
          :disable="!canCheck"
          :loading="busy"
          data-test="check"
          @click="check()"
        />
        <q-btn
          color="primary"
          no-caps
          :label="t('import.run')"
          :disable="!canImport"
          data-test="run"
          @click="run"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swmap-import-list {
  margin: 4px 0;
  padding-left: 18px;
  max-height: 160px;
  overflow: auto;
}
</style>

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import ImportDialog from '@/features/imports/ImportDialog.vue';
import { ApiError } from '@/api/client';
import { notifier } from './notifier';
import { configure, fakeApi, makeLayer, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

let api: ReturnType<typeof fakeApi>;
const dialog = () => document.body.querySelector<HTMLElement>('[data-test=import-dialog]');
const el = (selector: string) => document.body.querySelector<HTMLElement>(selector);
const click = async (selector: string) => {
  el(selector)?.click();
  await flushPromises();
};

async function open(layers = [makeLayer({ id: 10, title: 'Layer 1' })]) {
  const w = await mountView(ImportDialog, { modelValue: false, mapId: 1, layers });
  await w.setProps({ modelValue: true });
  await flushPromises();
  return w;
}

/** Selects a file through the component's own handler (the native file dialog cannot be driven in jsdom). */
async function pick(w: Awaited<ReturnType<typeof open>>, name: string, text: string) {
  const file = new File([text], name);
  Object.defineProperty(file, 'text', { value: async () => text });
  const input = w.findComponent({ name: 'QFile' });
  input.vm.$emit('update:modelValue', file);
  await flushPromises();
}

const report = (over: Record<string, unknown> = {}) => ({
  mode: 'new_layer',
  layer_id: 12,
  total: 2,
  created: 2,
  updated: 0,
  skipped: 0,
  error_count: 0,
  errors: [],
  warnings: [],
  columns: ['name', 'desc'],
  mapping: { title: 'name' },
  media_relink_hint: false,
  imported: false,
  preview: [{ title: 'A', type: 'Point', external_id: null }],
  ...over,
});

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  api = fakeApi();
  notifier.success.mockClear();
  notifier.error.mockClear();
});

describe('ImportDialog', () => {
  it('checks a file, shows the report and imports into a new layer', async () => {
    const w = await open();
    expect(dialog()).not.toBeNull();
    expect(el('[data-test=check]')?.hasAttribute('disabled')).toBe(true);
    await pick(w, 'huts.gpx', '<gpx/>');
    await click('[data-test=check]');
    const first = api.imports.run.mock.calls[0]![0] as Record<string, unknown>;
    expect(first).toMatchObject({
      map_id: 1,
      format: 'gpx',
      mode: 'new_layer',
      dry_run: true,
      layer_title: 'huts',
    });
    expect(el('[data-test=summary]')?.textContent).toContain('2 new');
    expect(el('[data-test=preview]')?.textContent).toContain('A');
    expect(el('[data-test=mapping]')).not.toBeNull();

    api.imports.run.mockResolvedValueOnce(report({ imported: true }) as never);
    await click('[data-test=run]');
    const second = api.imports.run.mock.calls[1]![0] as Record<string, unknown>;
    expect(second).toMatchObject({
      dry_run: false,
      skip_invalid: false,
      confirm_replace: false,
      mapping: { title: 'name' },
    });
    expect(notifier.success).toHaveBeenCalledWith('2 locations imported.');
    expect(w.emitted('imported')?.[0]).toEqual([12]);
  });

  it('sends explicit longitude and latitude columns for CSV only when given', async () => {
    const w = await open();
    await pick(w, 'a.csv', 'a');
    for (const [test, value] of [
      ['lon-column', 'X'],
      ['lat-column', ' Y '],
    ] as const) {
      const input = el(`input[data-test=${test}]`) as HTMLInputElement;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    await flushPromises();
    await click('[data-test=check]');
    expect(api.imports.run.mock.calls[0]![0]).toMatchObject({
      options: { delimiter: ',', decimal: '.', lon_column: 'X', lat_column: 'Y' },
    });
  });

  it('guesses the format from the extension and sends CSV options', async () => {
    const w = await open();
    await pick(w, 'places.csv', 'a,b');
    await click('[data-test=check]');
    expect(api.imports.run.mock.calls[0]![0]).toMatchObject({
      format: 'csv',
      options: { delimiter: ',', decimal: '.' },
    });
    await pick(w, 'x.geojson', '{}');
    await click('[data-test=check]');
    expect(api.imports.run.mock.calls[1]![0]).toMatchObject({ format: 'geojson' });
    expect(
      (api.imports.run.mock.calls[1]![0] as Record<string, unknown>)['options'],
    ).toBeUndefined();
    await pick(w, 'x.csv', 'a');
    await pick(w, 'x.txt', '');
    w.findComponent({ name: 'QFile' }).vm.$emit('update:modelValue', null);
    await flushPromises();
    expect(el('[data-test=check]')?.hasAttribute('disabled')).toBe(true);
  });

  it('blocks the import until invalid entries are skipped and shows errors and warnings', async () => {
    api.imports.run.mockResolvedValue(
      report({
        error_count: 1,
        errors: [
          { index: 2, code: 'no_geometry', message: 'Feature has no geometry.', params: {} },
        ],
        warnings: [
          { index: 1, code: 'field_invalid', message: 'x', params: { field: 'link' } },
          { index: 3, code: 'brand_new_code', message: 'Server fallback text', params: {} },
        ],
        media_relink_hint: true,
      }) as never,
    );
    const w = await open();
    await pick(w, 'a.geojson', '{}');
    await click('[data-test=check]');
    expect(el('[data-test=errors]')?.textContent).toContain('No geometry.');
    expect(el('[data-test=warnings]')?.textContent).toContain(
      'Field "link" is invalid and was left empty.',
    );
    expect(el('[data-test=warnings]')?.textContent).toContain('Server fallback text');
    expect(el('[data-test=media-hint]')).not.toBeNull();
    expect(el('[data-test=run]')?.hasAttribute('disabled')).toBe(true);
    await click('[data-test=skip-invalid] .q-checkbox__inner');
    expect(el('[data-test=run]')?.hasAttribute('disabled')).toBe(false);
  });

  it('needs a target layer and an explicit confirmation to replace', async () => {
    const w = await open();
    await pick(w, 'a.geojson', '{}');
    const select = w
      .findAllComponents({ name: 'QSelect' })
      .find((c) => c.vm.$attrs['data-test'] === 'mode')!;
    select.vm.$emit('update:modelValue', 'replace');
    await flushPromises();
    expect(el('[data-test=layer]')).not.toBeNull();
    await click('[data-test=check]');
    expect(api.imports.run.mock.calls[0]![0]).toMatchObject({ mode: 'replace', layer_id: 10 });
    expect(el('[data-test=run]')?.hasAttribute('disabled')).toBe(true);
    await click('[data-test=confirm-replace] .q-checkbox__inner');
    expect(el('[data-test=run]')?.hasAttribute('disabled')).toBe(false);
    await click('[data-test=run]');
    expect(api.imports.run.mock.calls[1]![0]).toMatchObject({
      confirm_replace: true,
      layer_id: 10,
    });
  });

  it('forgets the field mapping when another file is chosen', async () => {
    const w = await open();
    await pick(w, 'a.geojson', '{}');
    await click('[data-test=check]');
    await pick(w, 'b.geojson', '{}');
    await click('[data-test=check]');
    expect(api.imports.run.mock.calls[1]![0]).not.toHaveProperty('mapping');
  });

  it('drops a validation result that arrives after the inputs changed', async () => {
    const w = await open();
    await pick(w, 'a.geojson', '{}');
    let finish: (value: never) => void = () => undefined;
    api.imports.run.mockReturnValueOnce(new Promise((resolve) => (finish = resolve)) as never);
    await click('[data-test=check]');
    await pick(w, 'b.geojson', '{"other":1}');
    finish(report() as never);
    await flushPromises();
    expect(el('[data-test=summary]')).toBeNull();
    expect(el('[data-test=run]')?.hasAttribute('disabled')).toBe(true);
    api.imports.run.mockReturnValueOnce(
      new Promise((_, reject) => (finish = reject as never)) as never,
    );
    await click('[data-test=check]');
    await pick(w, 'c.geojson', '{"third":1}');
    finish(new Error('late') as never);
    await flushPromises();
    expect(el('[data-test=failure]')).toBeNull();
  });

  it('sends columns chosen as additional fields and re-checks', async () => {
    const w = await open();
    await pick(w, 'a.csv', 'a');
    await click('[data-test=check]');
    const select = w
      .findAllComponents({ name: 'QSelect' })
      .find((c) => c.vm.$attrs['data-test'] === 'extra-columns')!;
    select.vm.$emit('update:modelValue', ['desc']);
    await flushPromises();
    expect(api.imports.run.mock.calls[1]![0]).toMatchObject({ extra_columns: ['desc'] });
  });

  it('re-checks with the changed field mapping', async () => {
    const w = await open();
    await pick(w, 'a.geojson', '{}');
    await click('[data-test=check]');
    const select = w
      .findAllComponents({ name: 'QSelect' })
      .find((c) => c.vm.$attrs['data-test'] === 'map-description')!;
    select.vm.$emit('update:modelValue', 'desc');
    await flushPromises();
    expect(api.imports.run.mock.calls[1]![0]).toMatchObject({
      mapping: { title: 'name', description: 'desc' },
    });
  });

  it('translates rejection reasons and generic failures', async () => {
    const rejected = new ApiError('swmap_import_rejected', 'The file cannot be imported.', 422);
    rejected.reason = 'forbidden';
    api.imports.run.mockRejectedValueOnce(rejected);
    const w = await open();
    await pick(w, 'a.gpx', '<x/>');
    await click('[data-test=check]');
    expect(el('[data-test=failure]')?.textContent).toContain('forbidden XML declarations');
    api.imports.run.mockRejectedValueOnce(new Error('boom'));
    await click('[data-test=check]');
    expect(el('[data-test=failure]')?.textContent).toContain('The import failed.');
    api.imports.run.mockResolvedValueOnce(report() as never);
    await click('[data-test=check]');
    api.imports.run.mockRejectedValueOnce(
      new ApiError('swmap_invalid_param', 'Invalid input.', 400, [
        { field: 'file', message: '1 entries are invalid' },
      ]),
    );
    await click('[data-test=run]');
    expect(el('[data-test=failure]')?.textContent).toContain('Invalid input.');
  });

  it('resets when it is opened again', async () => {
    const w = await open([]);
    await pick(w, 'a.geojson', '{}');
    await click('[data-test=check]');
    expect(el('[data-test=summary]')).not.toBeNull();
    await w.setProps({ modelValue: false });
    await w.setProps({ modelValue: true });
    await flushPromises();
    expect(el('[data-test=summary]')).toBeNull();
  });
});

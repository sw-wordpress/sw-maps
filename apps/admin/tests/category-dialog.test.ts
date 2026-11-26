import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import CategoryDialog from '@/features/editor/CategoryDialog.vue';
import { ApiError } from '@/api/client';
import { notifier } from './notifier';
import { configure, makeCategory, mountView } from './helpers';

vi.mock('@/composables/useNotify', async () => {
  const { notifier: spy } = await import('./notifier');
  return { useNotify: () => spy };
});

const el = (selector: string) => document.body.querySelector<HTMLElement>(selector);
const all = (selector: string) => [...document.body.querySelectorAll<HTMLElement>(selector)];

beforeEach(() => {
  document.body.innerHTML = '';
  configure();
  notifier.success.mockClear();
  notifier.error.mockClear();
});

async function open(
  categories = [makeCategory()],
  save = vi.fn(async () => undefined),
  remove = vi.fn(async () => undefined),
) {
  const w = await mountView(CategoryDialog, { modelValue: true, categories, save, remove });
  await flushPromises();
  return { w, save, remove };
}

describe('CategoryDialog', () => {
  it('shows the empty state', async () => {
    await open([]);
    expect(el('[data-test=categories-empty]')).not.toBeNull();
  });

  it('creates a category with colour and icon', async () => {
    const { save } = await open([]);
    el('[data-test=category-add]')!.click();
    await flushPromises();
    expect(el('[data-test=category-save]')!.hasAttribute('disabled')).toBe(true);
    const title = el('input[data-test=category-title]') as HTMLInputElement;
    title.value = ' Lakes ';
    title.dispatchEvent(new Event('input'));
    const color = el('input[data-test=category-color]') as HTMLInputElement;
    color.value = '#112233';
    color.dispatchEvent(new Event('input'));
    await flushPromises();
    el('[data-test=category-save]')!.click();
    await flushPromises();
    expect(save).toHaveBeenCalledWith(null, {
      title: 'Lakes',
      style: { color: '#112233', icon: 'builtin:pin' },
    });
    expect(notifier.success).toHaveBeenCalledWith('Category saved.');
    expect(el('[data-test=category-form]')).toBeNull();
  });

  it('edits an existing category and deletes one', async () => {
    const { save, remove } = await open();
    expect(all('[data-test=category]')).toHaveLength(1);
    el('[data-test=category-edit]')!.click();
    await flushPromises();
    expect((el('input[data-test=category-title]') as HTMLInputElement).value).toBe('Huts');
    expect((el('input[data-test=category-color]') as HTMLInputElement).value).toBe('#00aa00');
    el('[data-test=category-save]')!.click();
    await flushPromises();
    expect(save).toHaveBeenCalledWith(20, {
      title: 'Huts',
      style: { color: '#00aa00', icon: 'builtin:home' },
    });
    el('[data-test=category-delete]')!.click();
    await flushPromises();
    expect(remove).toHaveBeenCalledWith(20);
    expect(notifier.success).toHaveBeenCalledWith('Category deleted.');
  });

  it('reports failures and keeps the form open', async () => {
    const save = vi.fn(async () => {
      throw new ApiError('swmap_conflict', 'This value is already in use.', 409);
    });
    await open([], save);
    el('[data-test=category-add]')!.click();
    await flushPromises();
    const title = el('input[data-test=category-title]') as HTMLInputElement;
    title.value = 'X';
    title.dispatchEvent(new Event('input'));
    await flushPromises();
    el('[data-test=category-save]')!.click();
    await flushPromises();
    expect(notifier.error).toHaveBeenCalledWith('This value is already in use.');
    expect(el('[data-test=category-form]')).not.toBeNull();
    // An empty title is never submitted.
    title.value = '  ';
    title.dispatchEvent(new Event('input'));
    await flushPromises();
    (el('input[data-test=category-title]') as HTMLInputElement).dispatchEvent(
      new KeyboardEvent('keyup', { key: 'Enter' }),
    );
    expect(save).toHaveBeenCalledTimes(1);
  });
});

import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../src/viewer.css', () => ({}));
vi.mock('../src/viewer', () => ({ initAll: vi.fn() }));

afterEach(() => vi.restoreAllMocks());

describe('entry', () => {
  it('starts immediately when the DOM is ready and waits otherwise', async () => {
    const { initAll } = await import('../src/viewer');
    const { run } = await import('../src/entry');
    expect(initAll).toHaveBeenCalledTimes(1);
    const loading = { readyState: 'loading', addEventListener: vi.fn() } as unknown as Document;
    run(loading);
    expect(loading.addEventListener).toHaveBeenCalledWith(
      'DOMContentLoaded',
      expect.any(Function),
      { once: true },
    );
    const handler = (loading.addEventListener as ReturnType<typeof vi.fn>).mock
      .calls[0]?.[1] as () => void;
    handler();
    expect(initAll).toHaveBeenCalledTimes(2);
    run({ readyState: 'complete' } as unknown as Document);
    expect(initAll).toHaveBeenCalledTimes(3);
  });
});

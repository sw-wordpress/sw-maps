import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';

vi.mock('quasar/dist/quasar.prod.css', () => ({}));
vi.mock('@quasar/extras/material-icons-round/material-icons-round.css', () => ({}));
vi.mock('@/styles/app.css', () => ({}));

beforeEach(() => {
  vi.resetModules();
  document.body.innerHTML = '';
  delete window.swmapsConfig;
});
afterEach(() => vi.restoreAllMocks());

describe('bootstrap', () => {
  it('logs and does nothing without a mount node', async () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    await import('@/main');
    expect(spy).toHaveBeenCalledWith(expect.stringContaining('mount node #sw-maps-app not found'));
  });

  it('shows a plain text error without configuration', async () => {
    document.body.innerHTML = '<div id="sw-maps-app"></div>';
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    await import('@/main');
    const el = document.getElementById('sw-maps-app')!;
    expect(el.querySelector('[role=alert]')?.textContent).toContain('configuration missing');
    expect(el.classList.contains('swmap-root')).toBe(true);
  });

  it('mounts the application with the PHP configuration', async () => {
    document.body.innerHTML = '<div id="sw-maps-app"></div>';
    window.swmapsConfig = {
      restRoot: 'https://example.org/wp-json/sw-maps/v1/',
      nonce: 'n',
      locale: 'de_DE',
      capabilities: ['swmap_edit_maps'],
    };
    window.location.hash = '#/';
    await import('@/main');
    await flushPromises();
    const el = document.getElementById('sw-maps-app')!;
    expect(el.classList.contains('swmap-root')).toBe(true);
    expect(el.querySelector('.swmap-nav')).not.toBeNull();
    expect(el.textContent).toContain('Karten');
  });
});

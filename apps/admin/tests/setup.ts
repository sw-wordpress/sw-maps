import { config } from '@vue/test-utils';
import { afterEach, vi } from 'vitest';
import { Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import en from '@/i18n/en';
import de from '@/i18n/de';

// Quasar needs matchMedia/ResizeObserver; jsdom has neither.
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
    onchange: null,
  })) as never;
}
globalThis.ResizeObserver = class {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
} as unknown as typeof ResizeObserver;

afterEach(async () => {
  const { unmountAll } = await import('./helpers');
  unmountAll();
});

export function createTestI18n(locale: 'en' | 'de' = 'en') {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en, de },
    missingWarn: false,
    fallbackWarn: false,
  });
}

config.global.plugins = [[Quasar, {}], createTestI18n()];

import type { SwmapConfig } from '@/types/config';

let cached: SwmapConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

/** Validate/normalise the PHP-provided config. Throws if the REST root is missing. */
export function normaliseConfig(raw: Partial<SwmapConfig> | undefined): SwmapConfig {
  if (!raw || typeof raw.restRoot !== 'string' || raw.restRoot === '') {
    throw new Error('window.swmapsConfig.restRoot is missing');
  }
  return {
    restRoot: raw.restRoot.endsWith('/') ? raw.restRoot : `${raw.restRoot}/`,
    nonce: str(raw.nonce, ''),
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    siteUrl: str(raw.siteUrl, ''),
    capabilities: Array.isArray(raw.capabilities)
      ? raw.capabilities.filter((c): c is string => typeof c === 'string')
      : [],
  };
}

export function getConfig(): SwmapConfig {
  if (cached === null) cached = normaliseConfig(window.swmapsConfig);
  return cached;
}

/** For tests. */
export function resetConfigForTests(): void {
  cached = null;
}

export const UI_LOCALES = ['en', 'de'] as const;
export type UiLocale = (typeof UI_LOCALES)[number];

/** German if the WordPress locale starts with `de`, otherwise English. */
export function resolveUiLocale(locale: string): UiLocale {
  return locale.toLowerCase().startsWith('de') ? 'de' : 'en';
}

export function hasCapability(config: SwmapConfig, capability: string): boolean {
  return config.capabilities.includes(capability);
}

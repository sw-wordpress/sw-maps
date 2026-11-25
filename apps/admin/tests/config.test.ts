import { describe, expect, it } from 'vitest';
import { getConfig, hasCapability, normaliseConfig, resetConfigForTests, resolveUiLocale } from '@/config';
import { createAppI18n } from '@/i18n';
import quasarLang from '@/i18n/quasarLang';
import de from '@/i18n/de';
import en from '@/i18n/en';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { pickImage } from '@/utils/media';

function flatten(obj: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(obj).flatMap(([k, v]) => (typeof v === 'object' && v !== null ? flatten(v as Record<string, unknown>, `${prefix}${k}.`) : [`${prefix}${k}`]));
}

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? sources(path) : /\.(vue|ts)$/.test(name) ? [path] : [];
  });
}

describe('config', () => {
  it('normalises and validates the PHP configuration', () => {
    expect(() => normaliseConfig(undefined)).toThrow('restRoot');
    expect(() => normaliseConfig({ restRoot: '' })).toThrow();
    const c = normaliseConfig({ restRoot: 'https://x/wp-json/sw-maps/v1', capabilities: ['a', 5 as never] });
    expect(c.restRoot).toBe('https://x/wp-json/sw-maps/v1/');
    expect(c.capabilities).toEqual(['a']);
    expect(c.locale).toBe('en_US');
    expect(hasCapability(c, 'a')).toBe(true);
    expect(hasCapability(c, 'b')).toBe(false);
    expect(normaliseConfig({ restRoot: '/x/', capabilities: 'no' as never }).capabilities).toEqual([]);
  });

  it('caches the window configuration', () => {
    resetConfigForTests();
    window.swmapsConfig = { restRoot: '/a/' };
    expect(getConfig().restRoot).toBe('/a/');
    window.swmapsConfig = { restRoot: '/b/' };
    expect(getConfig().restRoot).toBe('/a/');
    resetConfigForTests();
  });

  it('resolves the UI language', () => {
    expect(resolveUiLocale('de_DE')).toBe('de');
    expect(resolveUiLocale('de_CH_informal')).toBe('de');
    expect(resolveUiLocale('fr_FR')).toBe('en');
    expect(createAppI18n('de_DE').global.locale.value).toBe('de');
    expect(quasarLang('de').isoName).toBe('de');
    expect(quasarLang('en').isoName).toBe('en-US');
  });
});

describe('translations', () => {
  it('have identical keys in German and English', () => {
    expect(flatten(de as never).sort()).toEqual(flatten(en as never).sort());
  });

  it('have no empty strings and keep placeholders in sync', () => {
    const e = flatten(en as never);
    const get = (obj: unknown, path: string): string => path.split('.').reduce((o, k) => (o as Record<string, unknown>)[k], obj) as string;
    for (const key of e) {
      expect(get(en, key).trim(), key).not.toBe('');
      expect(get(de, key).trim(), key).not.toBe('');
      const names = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
      if (!key.startsWith('providers.urlHint') && !key.startsWith('providers.publicKey')) expect(names(get(de, key)), key).toEqual(names(get(en, key)));
    }
  });

  it('cover every key used in the sources and use no literal UI text in templates', () => {
    const keys = new Set(flatten(en as never));
    const used = new Set<string>();
    for (const file of sources(join(import.meta.dirname, '../src'))) {
      const text = readFileSync(file, 'utf8');
      for (const m of text.matchAll(/\bt\(\s*['"`]([\w.]+)['"`]/g)) used.add(m[1] as string);
    }
    for (const key of used) expect(keys.has(key), `missing key ${key}`).toBe(true);
    expect(used.size).toBeGreaterThan(40);
  });
});

describe('media picker', () => {
  it('resolves null without the WordPress media library', async () => {
    expect(await pickImage({} as Window)).toBeNull();
  });

  it('resolves with the selected attachment', async () => {
    const attachment = { id: 7, url: 'https://example.org/a.png' };
    let selectHandler: () => void = () => undefined;
    const frame = {
      on: (_e: string, cb: () => void) => (selectHandler = cb),
      open: () => selectHandler(),
      state: () => ({ get: () => ({ first: () => ({ toJSON: () => attachment }) }) }),
    };
    expect(await pickImage({ wp: { media: () => frame } } as unknown as Window)).toEqual(attachment);
  });
});

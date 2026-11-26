import { describe, expect, it, vi } from 'vitest';
import { hasConsent, onConsentChange } from '../src/consent';
import { Emitter } from '../src/emitter';
import { fromOlFeature, isPosition, toOlGeometry } from '../src/geometry';
import { markerSvg, resolveIcon } from '../src/markers';
import { buildPopup } from '../src/popup';
import { buildAttribution } from '../src/attribution';
import { hexToRgba } from '../src/styles';
import { strings } from './fixtures';
import Feature from 'ol/Feature.js';

describe('popup (XSS safety)', () => {
  const payload = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
  const props = {
    title: payload,
    description: payload,
    address: payload,
    image_id: 1,
    link: 'javascript:alert(1)',
    icon: 'builtin:pin',
    image_url: 'javascript:alert(3)',
  };

  it('renders every field as text, never as markup', () => {
    const el = buildPopup(props, strings, () => undefined);
    expect(el.querySelectorAll('img, script')).toHaveLength(0);
    expect(el.querySelector('.swmap-popup__title')?.textContent).toBe(payload);
    expect(el.querySelector('.swmap-popup__text')?.textContent).toBe(payload);
    expect(el.querySelector('a')).toBeNull();
    expect(el.innerHTML).not.toContain('<script');
    expect(el.querySelectorAll('[onerror]')).toHaveLength(0);
  });

  it('renders safe links and images', () => {
    const el = buildPopup(
      {
        ...props,
        title: 'T',
        description: '',
        address: '',
        link: 'https://example.org/x',
        image_url: 'https://example.org/i.jpg',
      },
      strings,
      () => undefined,
    );
    const a = el.querySelector('a');
    expect(a?.getAttribute('href')).toBe('https://example.org/x');
    expect(a?.rel).toContain('noopener');
    expect(a?.target).toBe('_blank');
    expect(el.querySelector('img')?.getAttribute('src')).toBe('https://example.org/i.jpg');
    const mail = buildPopup(
      { ...props, link: 'mailto:a@example.org' },
      strings,
      () => undefined,
    ).querySelector('a');
    expect(mail?.getAttribute('target')).toBeNull();
  });

  it('close button calls back without bubbling', () => {
    const onClose = vi.fn();
    const el = buildPopup(props, strings, onClose);
    const outer = vi.fn();
    document.body.append(el);
    document.body.addEventListener('click', outer);
    el.querySelector<HTMLButtonElement>('.swmap-popup__close')?.click();
    document.body.removeEventListener('click', outer);
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(outer).not.toHaveBeenCalled();
  });

  it('works without a title', () => {
    const el = buildPopup(
      { ...props, title: '', description: '', address: '', link: null, image_url: null },
      strings,
      () => undefined,
    );
    expect(el.querySelector('h3')).toBeNull();
    expect(el.getAttribute('aria-label')).toBe('Close');
  });
});

describe('attribution', () => {
  it('builds links with noopener and plain text nodes', () => {
    const el = buildAttribution('© [OSM](https://osm.org) and [bad](javascript:x)', 'Attribution');
    expect(el.querySelectorAll('a')).toHaveLength(1);
    expect(el.querySelector('a')?.rel).toContain('noopener');
    expect(el.textContent).toContain('bad');
    expect(el.getAttribute('aria-label')).toBe('Attribution');
  });
});

describe('consent', () => {
  it('is granted when not gated', () => {
    expect(hasConsent(null)).toBe(true);
  });
  it('fails closed without the Consent API', () => {
    expect(hasConsent('marketing', {} as Window)).toBe(false);
  });
  it('follows wp_has_consent and tolerates errors', () => {
    expect(hasConsent('marketing', { wp_has_consent: () => true } as unknown as Window)).toBe(true);
    expect(hasConsent('marketing', { wp_has_consent: () => false } as unknown as Window)).toBe(
      false,
    );
    expect(
      hasConsent('marketing', {
        wp_has_consent: () => {
          throw new Error('x');
        },
      } as unknown as Window),
    ).toBe(false);
  });
  it('subscribes and unsubscribes to consent events', () => {
    const cb = vi.fn();
    const off = onConsentChange(cb);
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    off();
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    expect(cb).toHaveBeenCalledTimes(1);
  });
});

describe('emitter', () => {
  it('isolates failing listeners and supports unsubscribe', () => {
    const e = new Emitter<{ a: number }>();
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const good = vi.fn();
    e.on('a', () => {
      throw new Error('boom');
    });
    const off = e.on('a', good);
    e.emit('a', 1);
    off();
    e.emit('a', 2);
    expect(good).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalled();
    e.clear();
    e.emit('a', 3);
    expect(good).toHaveBeenCalledTimes(1);
    spy.mockRestore();
  });
});

describe('markers and styles', () => {
  it('creates encoded svg data URIs for every glyph and falls back to the pin', () => {
    for (const name of [
      'pin',
      'star',
      'home',
      'flag',
      'info',
      'camera',
      'tree',
      'hiking',
      'restaurant',
      'hotel',
      'unknown',
    ]) {
      expect(markerSvg(name, '#112233')).toMatch(/^data:image\/svg\+xml/);
    }
    expect(decodeURIComponent(markerSvg('pin', '#112233'))).toContain('fill="#112233"');
  });
  it('resolves media icons only with a server URL and rejects unsafe colours', () => {
    const base = { title: '', description: '', address: '', image_id: null, link: null };
    expect(
      resolveIcon({ ...base, icon: 'media:5', icon_url: 'https://example.org/i.png' }, {}, {}),
    ).toEqual({ src: 'https://example.org/i.png', custom: true });
    expect(resolveIcon({ ...base, icon: 'media:5' }, {}, {}).custom).toBe(false);
    const evil = decodeURIComponent(
      resolveIcon({ ...base, icon: 'builtin:star' }, { color: '"/><script>' }, {}).src,
    );
    expect(evil).not.toContain('<script>');
  });
  it('converts hex colours', () => {
    expect(hexToRgba('#ff0000', 0.5)).toBe('rgba(255, 0, 0, 0.5)');
    expect(hexToRgba('#0f0', 2)).toBe('rgba(0, 255, 0, 1)');
  });
});

describe('geometry', () => {
  it('roundtrips GeoJSON through Web Mercator', () => {
    const g = toOlGeometry({
      type: 'LineString',
      coordinates: [
        [8.4, 49],
        [8.5, 49.1],
      ],
    });
    expect(g).not.toBeNull();
    const back = fromOlFeature(new Feature({ geometry: g as never }));
    const coords = back?.coordinates as number[][];
    expect(coords[0]?.[0]).toBeCloseTo(8.4, 6);
    expect(coords[1]?.[1]).toBeCloseTo(49.1, 6);
  });
  it('rejects broken input and empty features', () => {
    expect(toOlGeometry({ type: 'Nope' as never, coordinates: [] })).toBeNull();
    expect(fromOlFeature(new Feature())).toBeNull();
    expect(isPosition([1, 2])).toBe(true);
    expect(isPosition('x')).toBe(false);
  });
});

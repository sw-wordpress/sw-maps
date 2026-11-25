import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { MapRenderData } from '@sw-maps/map-contracts';
import { MapViewer, OpenLayersRenderer, initAll, readMapData } from '../src';
import type { ViewerDeps } from '../src/viewer';
import { makeData } from './fixtures';

/** Same encoding as the PHP side: tags, ampersands and quotes are hex escaped inside the JSON. */
function encodeForHtml(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, '\\u003C')
    .replace(/>/g, '\\u003E')
    .replace(/&/g, '\\u0026')
    .replace(/'/g, '\\u0027');
}

function markup(data: MapRenderData | string, attrs = ''): string {
  const json = typeof data === 'string' ? data : encodeForHtml(data);
  return `<div class="swmap" data-swmap="1" ${attrs}>
    <div class="swmap__stage"></div>
    <ul class="swmap__list">
      <li data-feature-id="1"><span class="swmap__item-title">Place 1</span><button type="button" hidden data-feature-id="1">Show</button></li>
      <li data-feature-id="2"><span class="swmap__item-title">Place 2</span><button type="button" hidden data-feature-id="2">Show</button></li>
    </ul>
    <script type="application/json" class="swmap__data">${json}</script>
  </div>`;
}

function deps(overrides: Partial<ViewerDeps> = {}): ViewerDeps {
  return {
    createRenderer: () => new OpenLayersRenderer({ popups: true }),
    hasConsent: () => true,
    onConsentChange: () => () => undefined,
    ...overrides,
  };
}

let observers: Array<{ cb: IntersectionObserverCallback; disconnect: ReturnType<typeof vi.fn> }> =
  [];

beforeEach(() => {
  document.body.innerHTML = '';
  observers = [];
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      disconnect = vi.fn();
      constructor(public cb: IntersectionObserverCallback) {
        observers.push(this);
      }
      observe(): void {}
    },
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function mount(html: string, d: ViewerDeps = deps()): { root: HTMLElement; viewer: MapViewer } {
  document.body.innerHTML = html;
  const root = document.querySelector<HTMLElement>('.swmap')!;
  const viewer = new MapViewer(root, d);
  viewer.start();
  return { root, viewer };
}

describe('readMapData', () => {
  it('parses hex escaped JSON and survives a closing script tag inside values', () => {
    const data = makeData();
    data.title = '</script><img src=x onerror=alert(1)>';
    document.body.innerHTML = markup(data);
    const parsed = readMapData(document.querySelector<HTMLElement>('.swmap')!);
    expect(parsed?.title).toBe(data.title);
    expect(document.querySelector('img')).toBeNull();
  });

  it.each([
    ['invalid json', '{nope'],
    ['not an object', '"x"'],
    ['null', 'null'],
    ['missing parts', '{"viewport":{}}'],
    ['empty', ''],
  ])('returns null for %s', (_name, json) => {
    document.body.innerHTML = markup(json);
    expect(readMapData(document.querySelector<HTMLElement>('.swmap')!)).toBeNull();
  });
});

describe('MapViewer lifecycle', () => {
  it('waits until the map becomes visible (lazy initialisation)', () => {
    const { root } = mount(markup(makeData()));
    expect(root.dataset['state']).toBe('idle');
    expect(observers).toHaveLength(1);
    observers[0]!.cb(
      [{ isIntersecting: false } as IntersectionObserverEntry],
      observers[0] as never,
    );
    expect(root.dataset['state']).toBe('idle');
    observers[0]!.cb(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      observers[0] as never,
    );
    expect(root.dataset['state']).toBe('ready');
    expect(observers[0]!.disconnect).toHaveBeenCalled();
    expect(root.querySelectorAll('.swmap__canvas')).toHaveLength(1);
  });

  it('starts immediately without IntersectionObserver or with data-lazy=0', () => {
    vi.stubGlobal('IntersectionObserver', undefined);
    expect(mount(markup(makeData())).root.dataset['state']).toBe('ready');
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        observe(): void {}
        disconnect(): void {}
      },
    );
    expect(mount(markup(makeData(), 'data-lazy="0"')).root.dataset['state']).toBe('ready');
  });

  it('shows a readable error and keeps the location list when the data is broken', () => {
    const { root } = mount(markup('{broken'));
    expect(root.dataset['state']).toBe('error');
    expect(root.querySelector('[role="alert"]')?.textContent).toBe(
      'The map could not be displayed.',
    );
    expect(root.querySelector('.swmap__list li')).not.toBeNull();
  });

  it('isolates a renderer failure and reports the translated message', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { root } = mount(
      markup(makeData(), 'data-lazy="0"'),
      deps({
        createRenderer: () => {
          throw new Error('webgl lost');
        },
      }),
    );
    expect(root.dataset['state']).toBe('error');
    expect(root.querySelector('[role="alert"]')?.textContent).toBe('Map error');
    expect(spy).toHaveBeenCalled();
  });

  it('shows a notice when the renderer reports tile errors (once)', () => {
    const renderer = new OpenLayersRenderer();
    const { root } = mount(
      markup(makeData(), 'data-lazy="0"'),
      deps({ createRenderer: () => renderer }),
    );
    (renderer as unknown as { events: { emit(e: string, p: unknown): void } }).events.emit(
      'error',
      new Error('x'),
    );
    (renderer as unknown as { events: { emit(e: string, p: unknown): void } }).events.emit(
      'error',
      new Error('x'),
    );
    expect(root.querySelectorAll('.swmap__notice')).toHaveLength(1);
  });

  it('destroy releases renderer, observer and list handlers', () => {
    const { root, viewer } = mount(markup(makeData(), 'data-lazy="0"'));
    viewer.destroy();
    expect(root.querySelector('.swmap__canvas')).toBeNull();
    root.querySelector<HTMLButtonElement>('button[data-feature-id="1"]')!.click();
    expect(root.querySelector('.swmap-popup')).toBeNull();
  });
});

describe('consent and click-to-load', () => {
  it('requests no tiles before consent and starts after the consent event', () => {
    let granted = false;
    let notify: () => void = () => undefined;
    const data = makeData({ consentCategory: 'marketing' });
    const { root } = mount(
      markup(data, 'data-lazy="0"'),
      deps({
        hasConsent: () => granted,
        onConsentChange: (cb) => ((notify = cb), () => undefined),
      }),
    );
    expect(root.dataset['state']).toBe('waiting-consent');
    expect(root.querySelector('.swmap__placeholder')?.textContent).toBe('Consent required');
    expect(root.querySelector('.swmap__canvas')).toBeNull();
    expect(root.querySelector('.swmap__list li')).not.toBeNull();
    notify();
    expect(root.dataset['state']).toBe('waiting-consent');
    granted = true;
    notify();
    expect(root.dataset['state']).toBe('ready');
    expect(root.querySelector('.swmap__placeholder')).toBeNull();
    notify();
    expect(root.dataset['state']).toBe('ready');
  });

  it('click-to-load keeps a local placeholder until the visitor clicks', () => {
    const data = makeData();
    data.settings = { ...data.settings, clickToLoad: true };
    const { root } = mount(markup(data, 'data-lazy="0"'));
    expect(root.dataset['state']).toBe('waiting-click');
    expect(root.querySelector('.swmap__canvas')).toBeNull();
    root.querySelector<HTMLButtonElement>('.swmap__load')!.click();
    expect(root.dataset['state']).toBe('ready');
  });

  it('click-to-load does not bypass a missing consent', () => {
    const data = makeData({ consentCategory: 'marketing' });
    data.settings = { ...data.settings, clickToLoad: true };
    const { root } = mount(markup(data, 'data-lazy="0"'), deps({ hasConsent: () => false }));
    expect(root.dataset['state']).toBe('waiting-consent');
    expect(root.querySelector('.swmap__load')).toBeNull();
  });
});

describe('location list synchronisation', () => {
  it('enables the list buttons once the map is ready and selects on click', () => {
    const { root } = mount(markup(makeData(), 'data-lazy="0"'));
    const buttons = root.querySelectorAll<HTMLButtonElement>('button[data-feature-id]');
    expect([...buttons].every((b) => !b.hidden)).toBe(true);
    buttons[1]!.click();
    expect(root.querySelector('li[data-feature-id="2"]')?.classList.contains('is-selected')).toBe(
      true,
    );
    expect(root.querySelector('li[data-feature-id="2"]')?.getAttribute('aria-current')).toBe(
      'true',
    );
    expect(root.querySelector('.swmap-popup__title')?.textContent).toBe('Place 2');
    buttons[0]!.click();
    expect(root.querySelector('li[data-feature-id="2"]')?.hasAttribute('aria-current')).toBe(false);
    root.querySelector<HTMLButtonElement>('.swmap-popup__close')!.click();
    expect(root.querySelector('li.is-selected')).toBeNull();
  });

  it('keeps the buttons hidden while the map is not loaded and ignores foreign clicks', () => {
    const data = makeData({ consentCategory: 'marketing' });
    const { root } = mount(markup(data, 'data-lazy="0"'), deps({ hasConsent: () => false }));
    expect(root.querySelector<HTMLButtonElement>('button[data-feature-id]')!.hidden).toBe(true);
    root.querySelector<HTMLElement>('.swmap__item-title')!.click();
    root.querySelector<HTMLButtonElement>('button[data-feature-id]')!.click();
    expect(root.querySelector('.swmap-popup')).toBeNull();
  });

  it('works without a list', () => {
    document.body.innerHTML = `<div class="swmap" data-swmap="1" data-lazy="0"><div class="swmap__stage"></div><script type="application/json" class="swmap__data">${encodeForHtml(makeData())}</script></div>`;
    const viewer = new MapViewer(document.querySelector<HTMLElement>('.swmap')!, deps());
    viewer.start();
    expect(viewer.currentState).toBe('ready');
  });

  it('fails without a stage element', () => {
    document.body.innerHTML = `<div class="swmap" data-swmap="1"><script type="application/json" class="swmap__data">${encodeForHtml(makeData())}</script></div>`;
    const viewer = new MapViewer(document.querySelector<HTMLElement>('.swmap')!, deps());
    viewer.start();
    expect(viewer.currentState).toBe('error');
  });
});

describe('initAll', () => {
  it('starts several independent maps once; one broken map does not affect the others', () => {
    const good = markup(makeData(), 'data-lazy="0"');
    const bad = markup('{broken', 'data-lazy="0"');
    document.body.innerHTML = good + bad + good;
    const viewers = initAll(document, deps());
    expect(viewers).toHaveLength(3);
    expect(viewers.map((v) => v.currentState)).toEqual(['ready', 'error', 'ready']);
    expect(initAll(document, deps())).toHaveLength(0);
    const canvases = document.querySelectorAll('.swmap__canvas');
    expect(canvases).toHaveLength(2);
    // Selecting in one map does not touch the other.
    const lists = document.querySelectorAll('.swmap');
    lists[0]!.querySelector<HTMLButtonElement>('button[data-feature-id="1"]')!.click();
    expect(lists[2]!.querySelector('.is-selected')).toBeNull();
  });

  it('survives a viewer that throws while starting', () => {
    document.body.innerHTML = markup(makeData(), 'data-lazy="0"');
    vi.spyOn(MapViewer.prototype, 'start').mockImplementation(() => {
      throw new Error('boom');
    });
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    expect(initAll(document, deps())).toHaveLength(1);
    expect(spy).toHaveBeenCalled();
  });
});

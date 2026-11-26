import type { MapRenderData, ViewerStrings } from '@sw-maps/map-contracts';
import { hasConsent, onConsentChange } from './consent';
import type { FilterBar, FilterState } from './filters';
import { buildFilterBar, matchingIds } from './filters';
import { OpenLayersRenderer } from './renderer';

type State = 'idle' | 'waiting-consent' | 'waiting-click' | 'loading' | 'ready' | 'error';

const FALLBACK_ERROR = 'The map could not be displayed.';

export interface ViewerDeps {
  createRenderer: () => OpenLayersRenderer;
  hasConsent: (category: string | null) => boolean;
  onConsentChange: (callback: () => void) => () => void;
}

const defaultDeps: ViewerDeps = {
  createRenderer: () => new OpenLayersRenderer({ popups: true }),
  hasConsent: (category) => hasConsent(category),
  onConsentChange: (callback) => onConsentChange(callback),
};

/** Parses the embedded JSON. Returns null for anything that is not a usable map payload. */
export function readMapData(root: HTMLElement): MapRenderData | null {
  const script = root.querySelector<HTMLScriptElement>(
    'script.swmap__data[type="application/json"]',
  );
  if (!script?.textContent) return null;
  try {
    const data = JSON.parse(script.textContent) as Partial<MapRenderData>;
    if (
      typeof data !== 'object' ||
      data === null ||
      typeof data.viewport !== 'object' ||
      typeof data.settings !== 'object' ||
      !Array.isArray(data.layers) ||
      typeof data.strings !== 'object'
    ) {
      return null;
    }
    return data as MapRenderData;
  } catch {
    return null;
  }
}

/**
 * One map on the page. Failures are isolated: an error in one viewer never affects another, and the server rendered
 * location list stays usable in every state.
 */
export class MapViewer {
  private state: State = 'idle';
  private renderer: OpenLayersRenderer | null = null;
  private data: MapRenderData | null = null;
  private clicked = false;
  private observer: IntersectionObserver | null = null;
  private unsubscribeConsent: (() => void) | null = null;
  private readonly stage: HTMLElement | null;
  private readonly list: HTMLElement | null;
  private readonly listCleanup: Array<() => void> = [];
  private filterBar: FilterBar | null = null;
  private filterState: FilterState | null = null;

  constructor(
    private readonly root: HTMLElement,
    private readonly deps: ViewerDeps = defaultDeps,
  ) {
    this.stage = root.querySelector<HTMLElement>('.swmap__stage');
    this.list = root.querySelector<HTMLElement>('.swmap__list');
  }

  get currentState(): State {
    return this.state;
  }

  start(): void {
    this.setState('idle');
    this.data = readMapData(this.root);
    if (!this.data || !this.stage) {
      this.fail(this.data?.strings.error);
      return;
    }
    this.bindList();
    this.setupFilters(this.data);
    this.watchConsent();
    const lazy = this.root.dataset['lazy'] !== '0' && typeof IntersectionObserver !== 'undefined';
    if (!lazy) {
      this.activate();
      return;
    }
    this.observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          this.observer?.disconnect();
          this.observer = null;
          this.activate();
        }
      },
      { rootMargin: '200px' },
    );
    this.observer.observe(this.stage);
  }

  destroy(): void {
    this.observer?.disconnect();
    this.unsubscribeConsent?.();
    this.unsubscribeConsent = null;
    this.renderer?.destroy();
    this.renderer = null;
    for (const fn of this.listCleanup.splice(0)) fn();
    this.filterBar?.destroy();
    this.filterBar = null;
  }

  private activate(): void {
    const data = this.data;
    if (!data || this.state === 'ready' || this.state === 'loading') return;
    if (!this.deps.hasConsent(data.consentCategory)) {
      this.setState('waiting-consent');
      this.showPlaceholder(data.strings.consentRequired, null);
      return;
    }
    if (data.settings.clickToLoad && !this.clicked) {
      this.setState('waiting-click');
      this.showPlaceholder(data.strings.clickToLoad, data.strings.loadMap);
      return;
    }
    this.load(data);
  }

  /**
   * For the lifetime of a consent-gated viewer: granting consent starts the map, withdrawing it stops all tile
   * requests at once (the renderer is destroyed and the placeholder returns).
   */
  private watchConsent(): void {
    const category = this.data?.consentCategory ?? null;
    if (category === null || this.unsubscribeConsent !== null) return;
    this.unsubscribeConsent = this.deps.onConsentChange(() => {
      if (this.state === 'waiting-consent') {
        this.activate();
      } else if (
        (this.state === 'ready' || this.state === 'loading') &&
        !this.deps.hasConsent(category)
      ) {
        this.withdraw();
      }
    });
  }

  private withdraw(): void {
    const data = this.data;
    if (!data) return;
    this.renderer?.destroy();
    this.renderer = null;
    this.clicked = false;
    this.stage?.querySelector('.swmap__notice')?.remove();
    for (const button of this.list?.querySelectorAll<HTMLButtonElement>(
      'button[data-feature-id]',
    ) ?? [])
      button.hidden = true;
    this.markSelected(null);
    this.setState('waiting-consent');
    this.showPlaceholder(data.strings.consentRequired, null);
  }

  private load(data: MapRenderData): void {
    if (!this.stage) return;
    this.setState('loading');
    this.clearPlaceholder();
    try {
      const renderer = this.deps.createRenderer();
      renderer.on('error', () => this.showNotice(data.strings.error));
      renderer.on('select', (id) => this.markSelected(id));
      renderer.mount(this.stage, { data });
      this.renderer = renderer;
      this.applyFilter();
      this.setState('ready');
      this.enableListButtons();
    } catch (error) {
      console.error('sw-maps: map failed to start', error);
      this.renderer?.destroy();
      this.renderer = null;
      this.fail(data.strings.error);
    }
  }

  private showPlaceholder(message: string, buttonLabel: string | null): void {
    if (!this.stage) return;
    this.clearPlaceholder();
    const box = this.stage.ownerDocument.createElement('div');
    box.className = 'swmap__placeholder';
    const text = this.stage.ownerDocument.createElement('p');
    text.textContent = message;
    box.append(text);
    if (buttonLabel !== null) {
      const button = this.stage.ownerDocument.createElement('button');
      button.type = 'button';
      button.className = 'swmap__load';
      button.textContent = buttonLabel;
      button.addEventListener('click', () => {
        this.clicked = true;
        this.activate();
      });
      box.append(button);
    }
    this.stage.append(box);
  }

  private clearPlaceholder(): void {
    this.stage?.querySelector('.swmap__placeholder')?.remove();
  }

  private showNotice(message: string): void {
    if (!this.stage || this.stage.querySelector('.swmap__notice')) return;
    const notice = this.stage.ownerDocument.createElement('p');
    notice.className = 'swmap__notice';
    notice.setAttribute('role', 'status');
    notice.textContent = message;
    this.stage.append(notice);
  }

  private fail(message?: string): void {
    this.setState('error');
    this.clearPlaceholder();
    if (!this.stage) return;
    const box = this.stage.ownerDocument.createElement('div');
    box.className = 'swmap__placeholder swmap__placeholder--error';
    box.setAttribute('role', 'alert');
    const text = this.stage.ownerDocument.createElement('p');
    text.textContent = message ?? FALLBACK_ERROR;
    box.append(text);
    this.stage.append(box);
  }

  private setState(state: State): void {
    this.state = state;
    this.root.dataset['state'] = state;
  }

  private bindList(): void {
    if (!this.list) return;
    const onClick = (event: Event): void => {
      const button = (event.target as Element | null)?.closest<HTMLButtonElement>(
        'button[data-feature-id]',
      );
      if (!button || !this.renderer) return;
      this.renderer.selectFeature(Number(button.dataset['featureId']), { centre: true });
    };
    this.list.addEventListener('click', onClick);
    this.listCleanup.push(() => this.list?.removeEventListener('click', onClick));
  }

  /** Search field and category legend above the map; they filter the list and the map together. */
  private setupFilters(data: MapRenderData): void {
    const bar = buildFilterBar(this.root.ownerDocument, data, (state) => {
      this.filterState = state;
      this.applyFilter();
    });
    if (!bar || !this.stage) return;
    this.filterBar = bar;
    this.stage.before(bar.element);
  }

  private applyFilter(): void {
    const data = this.data;
    const state = this.filterState;
    if (!data || !state) return;
    const { ids, active } = matchingIds(data, state);
    for (const item of this.list?.querySelectorAll<HTMLElement>('li[data-feature-id]') ?? []) {
      item.hidden = active && !ids.has(Number(item.dataset['featureId']));
    }
    this.renderer?.setVisibleIds(active ? ids : null);
    this.showEmpty(active && ids.size === 0 ? data.strings.noResults : null);
  }

  private showEmpty(message: string | null): void {
    const existing = this.root.querySelector('.swmap__empty');
    if (message === null) {
      existing?.remove();
      return;
    }
    if (existing) {
      existing.textContent = message;
      return;
    }
    const note = this.root.ownerDocument.createElement('p');
    note.className = 'swmap__empty';
    note.setAttribute('role', 'status');
    note.textContent = message;
    (this.list ?? this.stage)?.before(note);
  }

  private enableListButtons(): void {
    for (const button of this.list?.querySelectorAll<HTMLButtonElement>(
      'button[data-feature-id]',
    ) ?? []) {
      button.hidden = false;
    }
  }

  private markSelected(id: number | null): void {
    for (const item of this.list?.querySelectorAll<HTMLElement>('li[data-feature-id]') ?? []) {
      const active = id !== null && Number(item.dataset['featureId']) === id;
      item.classList.toggle('is-selected', active);
      if (active) item.setAttribute('aria-current', 'true');
      else item.removeAttribute('aria-current');
    }
  }
}

/** Starts a viewer for every map container below `root`. Each viewer is isolated from the others. */
export function initAll(root: ParentNode = document, deps?: ViewerDeps): MapViewer[] {
  const viewers: MapViewer[] = [];
  for (const el of root.querySelectorAll<HTMLElement>('.swmap[data-swmap]')) {
    if (el.dataset['swmapReady'] === '1') continue;
    el.dataset['swmapReady'] = '1';
    const viewer = new MapViewer(el, deps);
    try {
      viewer.start();
    } catch (error) {
      console.error('sw-maps: viewer failed', error);
    }
    viewers.push(viewer);
  }
  return viewers;
}

export type { ViewerStrings };

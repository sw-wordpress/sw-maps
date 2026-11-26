import { safeColor } from '@sw-maps/map-contracts';
import type { MapRenderData } from '@sw-maps/map-contracts';
import { DEFAULT_COLOR, markerSvg } from './markers';

const MIN_FEATURES_FOR_SEARCH = 5;

export interface FilterState {
  query: string;
  categories: ReadonlySet<number>;
}

/** Ids of the features that match the search text and (any of) the selected categories. */
export function matchingIds(
  data: MapRenderData,
  state: FilterState,
): { ids: Set<number>; active: boolean } {
  const query = state.query.trim().toLowerCase();
  const ids = new Set<number>();
  for (const layer of data.layers) {
    for (const feature of layer.features) {
      const p = feature.properties;
      const text = `${p.title} ${p.address} ${p.description}`.toLowerCase();
      const inCategory =
        state.categories.size === 0 ||
        (feature.categories ?? []).some((c) => state.categories.has(c));
      if (inCategory && (query === '' || text.includes(query))) ids.add(feature.id);
    }
  }
  return { ids, active: query !== '' || state.categories.size > 0 };
}

export interface FilterBar {
  element: HTMLElement;
  destroy(): void;
}

/**
 * Search field plus one toggle button per category. The button shows the category's marker (shape and colour), so
 * the legend does not rely on colour alone. Returns null when there is nothing to filter.
 */
export function buildFilterBar(
  doc: Document,
  data: MapRenderData,
  onChange: (state: FilterState) => void,
): FilterBar | null {
  const categories = data.categories ?? [];
  const total = data.layers.reduce((n, l) => n + l.features.length, 0);
  const searchable = total >= MIN_FEATURES_FOR_SEARCH;
  if (categories.length === 0 && !searchable) return null;

  const element = doc.createElement('div');
  element.className = 'swmap__filters';
  const selected = new Set<number>();
  let query = '';
  const emit = (): void => onChange({ query, categories: selected });
  const cleanup: Array<() => void> = [];

  if (searchable) {
    const input = doc.createElement('input');
    input.type = 'search';
    input.className = 'swmap__search';
    input.setAttribute('aria-label', data.strings.search);
    input.placeholder = data.strings.search;
    const onInput = (): void => {
      query = input.value;
      emit();
    };
    input.addEventListener('input', onInput);
    cleanup.push(() => input.removeEventListener('input', onInput));
    element.append(input);
  }
  if (categories.length > 0) {
    const group = doc.createElement('div');
    group.className = 'swmap__legend';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', data.strings.filterByCategory);
    for (const category of categories) {
      const button = doc.createElement('button');
      button.type = 'button';
      button.className = 'swmap__chip';
      button.setAttribute('aria-pressed', 'false');
      const icon = category.style['icon'];
      const image = doc.createElement('img');
      image.alt = '';
      image.width = 16;
      image.height = 21;
      image.src = markerSvg(
        typeof icon === 'string' && icon.startsWith('builtin:') ? icon.slice(8) : 'pin',
        safeColor(category.style['color'], DEFAULT_COLOR),
      );
      const label = doc.createElement('span');
      label.textContent = category.title;
      button.append(image, label);
      const onClick = (): void => {
        const on = !selected.has(category.id);
        if (on) selected.add(category.id);
        else selected.delete(category.id);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        emit();
      };
      button.addEventListener('click', onClick);
      cleanup.push(() => button.removeEventListener('click', onClick));
      group.append(button);
    }
    element.append(group);
  }
  return {
    element,
    destroy: () => {
      for (const fn of cleanup.splice(0)) fn();
      element.remove();
    },
  };
}

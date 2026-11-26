import { mount, flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import type { Component } from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import type {
  CategoryData,
  FeatureData,
  LayerData,
  MapData,
  ProviderData,
} from '@sw-maps/map-contracts';
import { vi } from 'vitest';
import type { Mock } from 'vitest';
import type { Api } from '@/api';
import { setApiForTests } from '@/api';
import { resetConfigForTests } from '@/config';

export function configure(
  capabilities: string[] = [
    'swmap_edit_maps',
    'swmap_publish_maps',
    'swmap_manage_layers',
    'swmap_manage_imports',
    'swmap_manage_system',
  ],
): void {
  resetConfigForTests();
  window.swmapsConfig = {
    restRoot: 'https://example.org/wp-json/sw-maps/v1/',
    nonce: 'n',
    locale: 'en_US',
    version: '0.1.0',
    adminUrl: '',
    siteUrl: '',
    capabilities,
  };
}

export function makeMap(overrides: Partial<MapData> = {}): MapData {
  return {
    id: 1,
    slug: 'test',
    title: 'Test map',
    description: '',
    status: 'draft',
    viewport: {
      center: [8.4, 49],
      zoom: 10,
      minZoom: 2,
      maxZoom: 18,
      bounds: null,
      autoBounds: true,
    },
    settings: {
      height: 400,
      aspectRatio: null,
      scrollZoom: false,
      touch: true,
      drag: true,
      zoomControls: true,
      fullscreen: true,
      scale: true,
      showList: true,
      clickToLoad: false,
      cluster: true,
      ajax: false,
      providerId: null,
    },
    layer_count: 1,
    feature_count: 2,
    created_at: '',
    updated_at: '',
    ...overrides,
  };
}

export function makeLayer(overrides: Partial<LayerData> = {}): LayerData {
  return {
    id: 10,
    map_id: 1,
    title: 'Layer 1',
    type: 'features',
    status: 'published',
    sort_order: 0,
    visible: true,
    style: {},
    created_at: '',
    updated_at: '',
    ...overrides,
  };
}

export function makeFeature(overrides: Partial<FeatureData> = {}): FeatureData {
  return {
    id: 100,
    layer_id: 10,
    external_id: null,
    geometry: { type: 'Point', coordinates: [8.4, 49] },
    properties: {
      title: 'Rathaus',
      description: '',
      address: '',
      image_id: null,
      link: null,
      icon: 'builtin:pin',
    },
    style: {},
    updated_at: '',
    ...overrides,
  };
}

export function makeCategory(overrides: Partial<CategoryData> = {}): CategoryData {
  return {
    id: 20,
    slug: 'huts',
    title: 'Huts',
    style: { color: '#00aa00', icon: 'builtin:home' },
    ...overrides,
  };
}

export function makeProvider(overrides: Partial<ProviderData> = {}): ProviderData {
  return {
    id: 1,
    type: 'xyz',
    name: 'OpenStreetMap',
    public_config: {
      urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      attribution: '© OSM',
      minZoom: 0,
      maxZoom: 19,
      tileSize: 256,
      publicKey: '',
    },
    secret_constant: null,
    secret_defined: false,
    external_requests: ['tile.openstreetmap.org'],
    enabled: true,
    ...overrides,
  };
}

type MockedGroup<T> = {
  [M in keyof T]: T[M] extends (...args: infer A) => infer R ? Mock<(...args: A) => R> : never;
};
export type FakeApi = { [K in keyof Api]: MockedGroup<Api[K]> };

/** A fully mocked API (every method is a vi.fn with sensible defaults). */
export function fakeApi(): FakeApi {
  const api = {
    maps: {
      list: vi.fn(async () => ({ items: [makeMap()], total: 1, totalPages: 1 })),
      get: vi.fn(async () => makeMap()),
      create: vi.fn(async (b: Record<string, unknown>) =>
        makeMap({ id: 5, title: String(b['title']) }),
      ),
      update: vi.fn(async () => makeMap()),
      remove: vi.fn(async () => undefined),
      duplicate: vi.fn(async () => makeMap({ id: 6 })),
    },
    layers: {
      forMap: vi.fn(async () => [makeLayer()]),
      create: vi.fn(async () => makeLayer({ id: 11 })),
      update: vi.fn(async () => makeLayer()),
      remove: vi.fn(async () => undefined),
    },
    features: {
      page: vi.fn(async () => ({ items: [makeFeature()], total: 1, totalPages: 1 })),
      create: vi.fn(async () => makeFeature({ id: 101 })),
      update: vi.fn(async () => makeFeature()),
      remove: vi.fn(async () => undefined),
    },
    categories: {
      list: vi.fn(async () => [makeCategory()]),
      create: vi.fn(async (b: Record<string, unknown>) =>
        makeCategory({ id: 21, title: String(b['title']) }),
      ),
      update: vi.fn(async (id: number, b: Record<string, unknown>) =>
        makeCategory({ id, title: String(b['title'] ?? 'Huts') }),
      ),
      remove: vi.fn(async () => undefined),
    },
    providers: {
      list: vi.fn(async () => [makeProvider()]),
      create: vi.fn(async () => makeProvider({ id: 2 })),
      update: vi.fn(async () => makeProvider()),
      remove: vi.fn(async () => undefined),
      test: vi.fn(async () => ({ ok: true, status: 200, message: 'Connection successful.' })),
    },
    settings: {
      get: vi.fn(async () => ({
        delete_data_on_uninstall: false,
        default_height: 400,
        default_scroll_zoom: false,
        max_features_per_layer: 5000,
        click_to_load: false,
        allowed_hosts: [],
        geocoder_url: 'https://nominatim.openstreetmap.org',
        geocoder_contact: '',
      })),
      update: vi.fn(async (b: Record<string, unknown>) => ({
        delete_data_on_uninstall: false,
        default_height: 400,
        default_scroll_zoom: false,
        max_features_per_layer: 5000,
        click_to_load: false,
        allowed_hosts: [],
        geocoder_url: '',
        geocoder_contact: '',
        ...b,
      })),
    },
    geocoding: {
      search: vi.fn(async () => ({
        results: [
          { label: 'Karlsruhe Schloss', lon: 8.4037, lat: 49.0069 },
          { label: 'Karlsruhe Hbf', lon: 8.4, lat: 49.0 },
        ],
      })),
      reverse: vi.fn(async () => ({ result: { label: 'Kaiserstraße 1', lon: 8.4, lat: 49 } })),
    },
    exports: {
      map: vi.fn(async (_id: number, format: string) => ({
        filename: `map.${format}`,
        mime_type: 'application/json',
        content: '{}',
      })),
      all: vi.fn(async () => ({
        filename: 'all.json',
        mime_type: 'application/json',
        content: '{}',
      })),
    },
    imports: {
      package: vi.fn(async () => ({
        maps: [{ id: 30, title: 'Imported', layers: 1, features: 2 }],
        categories_created: 1,
        categories_matched: 0,
        media_relink_hint: true,
      })),
      run: vi.fn(async () => ({
        mode: 'new_layer',
        layer_id: 12,
        total: 2,
        created: 2,
        updated: 0,
        skipped: 0,
        error_count: 0,
        errors: [],
        warnings: [],
        columns: ['name'],
        mapping: { title: 'name' },
        media_relink_hint: false,
        imported: false,
        preview: [{ title: 'A', type: 'Point', external_id: null }],
      })),
    },
    status: {
      providers: vi.fn(async () => ({
        providers: [
          {
            id: 1,
            name: 'OpenStreetMap',
            type: 'xyz',
            host: 'tile.openstreetmap.org',
            ok: true,
            status: 200,
            message: 'Connection successful.',
          },
          {
            id: 2,
            name: 'Own tiles',
            type: 'xyz',
            host: 'tiles.example.org',
            ok: false,
            status: 503,
            message: 'The tile server did not return a tile.',
          },
        ],
      })),
      get: vi.fn(async () => ({
        plugin_version: '0.1.0',
        db_version: 1,
        db_expected: 1,
        schema_ok: true,
        limits: {
          max_features_per_layer: 5000,
          import_max_bytes: 5242880,
          import_max_coords: 200000,
          public_page_size: 500,
          php_memory_limit: '256M',
          php_max_execution_time: 30,
          php_post_max_size: '8M',
        },
        tables: [
          { name: 'wp_swmap_maps', exists: true },
          { name: 'wp_swmap_layers', exists: false },
        ],
        php_version: '8.3.0',
        wp_version: '6.8',
        db_server: 'MariaDB 11',
        multisite: false,
        environment: 'local',
        consent_api: false,
        settings: {
          delete_data_on_uninstall: false,
          default_height: 400,
          default_scroll_zoom: false,
          max_features_per_layer: 5000,
          click_to_load: false,
          allowed_hosts: [],
          geocoder_url: '',
          geocoder_contact: '',
        },
      })),
    },
  };
  setApiForTests(api as unknown as Api);
  return api as unknown as FakeApi;
}

export function setupPinia(): void {
  setActivePinia(createPinia());
}

const mounted: VueWrapper[] = [];

/** Unmounts everything mounted through mountView (called after each test from tests/setup.ts). */
export function unmountAll(): void {
  for (const wrapper of mounted.splice(0)) wrapper.unmount();
}

export async function mountView(
  component: Component,
  props: Record<string, unknown> = {},
  extra: Record<string, unknown> = {},
) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const wrapper = mount(component, {
    props,
    attachTo: document.body,
    global: {
      plugins: [pinia],
      stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } },
      ...extra,
    },
  });
  mounted.push(wrapper as VueWrapper);
  await flushPromises();
  return wrapper;
}

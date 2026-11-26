import type {
  CategoryData,
  FeatureData,
  LayerData,
  MapData,
  ProviderData,
} from '@sw-maps/map-contracts';
import { getConfig } from '@/config';
import type { Paged, RequestOptions } from './client';
import { ApiClient } from './client';

export interface SettingsData {
  delete_data_on_uninstall: boolean;
  default_height: number;
  default_scroll_zoom: boolean;
  max_features_per_layer: number;
  click_to_load: boolean;
}

export interface StatusData {
  plugin_version: string;
  db_version: number;
  db_expected: number;
  tables: { name: string; exists: boolean }[];
  php_version: string;
  wp_version: string;
  db_server: string;
  multisite: boolean;
  environment: string;
  consent_api: boolean;
  settings: SettingsData;
}

export interface ConnectionTest {
  ok: boolean;
  status: number;
  message: string;
}

export interface ImportIssue {
  index: number;
  /** Stable code; the client shows the translated text and falls back to `message`. */
  code: string;
  message: string;
  params: Record<string, string>;
}

export interface ImportReport {
  mode: string;
  layer_id: number | null;
  total: number;
  created: number;
  updated: number;
  skipped: number;
  error_count: number;
  errors: ImportIssue[];
  warnings: ImportIssue[];
  columns: string[];
  mapping: Record<string, string>;
  media_relink_hint: boolean;
  imported: boolean;
  preview: { title: string; type: string; external_id: string | null }[];
}

export type Body = Record<string, unknown>;

export function createApi(client: ApiClient) {
  return {
    maps: {
      list: (
        query: { search?: string; status?: string; page?: number; per_page?: number } = {},
        o: RequestOptions = {},
      ): Promise<Paged<MapData>> => client.list<MapData>('maps', { ...o, query }),
      get: (id: number) => client.request<MapData>('GET', `maps/${id}`),
      create: (body: Body) => client.request<MapData>('POST', 'maps', { body }),
      update: (id: number, body: Body) => client.request<MapData>('PATCH', `maps/${id}`, { body }),
      remove: (id: number) => client.request<void>('DELETE', `maps/${id}`),
      duplicate: (id: number) => client.request<MapData>('POST', `maps/${id}/duplicate`),
    },
    layers: {
      forMap: (mapId: number) => client.request<LayerData[]>('GET', `maps/${mapId}/layers`),
      create: (mapId: number, body: Body) =>
        client.request<LayerData>('POST', `maps/${mapId}/layers`, { body }),
      update: (id: number, body: Body) =>
        client.request<LayerData>('PATCH', `layers/${id}`, { body }),
      remove: (id: number) => client.request<void>('DELETE', `layers/${id}`),
    },
    features: {
      page: (layerId: number, page = 1, perPage = 500) =>
        client.list<FeatureData>(`layers/${layerId}/features`, {
          query: { page, per_page: perPage },
        }),
      create: (layerId: number, body: Body) =>
        client.request<FeatureData>('POST', `layers/${layerId}/features`, { body }),
      update: (id: number, body: Body) =>
        client.request<FeatureData>('PATCH', `features/${id}`, { body }),
      remove: (id: number) => client.request<void>('DELETE', `features/${id}`),
    },
    categories: {
      list: () => client.request<CategoryData[]>('GET', 'categories'),
      create: (body: Body) => client.request<CategoryData>('POST', 'categories', { body }),
      update: (id: number, body: Body) =>
        client.request<CategoryData>('PATCH', `categories/${id}`, { body }),
      remove: (id: number) => client.request<void>('DELETE', `categories/${id}`),
    },
    providers: {
      list: () => client.request<ProviderData[]>('GET', 'providers'),
      create: (body: Body) => client.request<ProviderData>('POST', 'providers', { body }),
      update: (id: number, body: Body) =>
        client.request<ProviderData>('PATCH', `providers/${id}`, { body }),
      remove: (id: number) => client.request<void>('DELETE', `providers/${id}`),
      test: (id: number) => client.request<ConnectionTest>('POST', `providers/${id}/test`),
    },
    settings: {
      get: () => client.request<SettingsData>('GET', 'settings'),
      update: (body: Partial<SettingsData>) =>
        client.request<SettingsData>('PATCH', 'settings', { body }),
    },
    status: {
      get: () => client.request<StatusData>('GET', 'status'),
    },
    imports: {
      run: (body: Body) => client.request<ImportReport>('POST', 'imports', { body }),
    },
  };
}

export type Api = ReturnType<typeof createApi>;

let instance: Api | null = null;

/** The shared API instance (created from the PHP provided configuration on first use). */
export function api(): Api {
  if (instance === null) {
    const config = getConfig();
    instance = createApi(new ApiClient({ restRoot: config.restRoot, nonce: config.nonce }));
  }
  return instance;
}

/** For tests: inject a fake API (or null to reset). */
export function setApiForTests(next: Api | null): void {
  instance = next;
}

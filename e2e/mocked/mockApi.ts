import type { Page, Route } from '@playwright/test';

/** 1x1 PNG for tiles. */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);

type Json = Record<string, unknown>;

interface State {
  seq: number;
  maps: Json[];
  layers: Json[];
  features: Json[];
  providers: Json[];
  settings: Json;
}

const now = '2026-01-01T00:00:00Z';

function initial(): State {
  return {
    seq: 100,
    maps: [],
    layers: [],
    features: [],
    providers: [
      {
        id: 1,
        type: 'xyz',
        name: 'OpenStreetMap',
        public_config: {
          urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
          attribution: '© [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors',
          minZoom: 0,
          maxZoom: 19,
          tileSize: 256,
          publicKey: '',
        },
        secret_constant: null,
        secret_defined: false,
        external_requests: ['tile.openstreetmap.org'],
        enabled: true,
      },
      {
        id: 2,
        type: 'xyz',
        name: 'Own tiles',
        public_config: {
          urlTemplate: 'https://tiles.example.org/{z}/{x}/{y}.png',
          attribution: '© Own',
          minZoom: 0,
          maxZoom: 19,
          tileSize: 256,
          publicKey: '',
        },
        secret_constant: null,
        secret_defined: false,
        external_requests: ['tiles.example.org'],
        enabled: false,
      },
    ],
    settings: {
      delete_data_on_uninstall: false,
      default_height: 400,
      default_scroll_zoom: false,
      max_features_per_layer: 5000,
      click_to_load: false,
    },
  };
}

export interface MockApi {
  state: State;
  requests: string[];
}

/** Stateful simulation of the sw-maps/v1 REST API (the subset the admin app uses). */
export async function mockApi(page: Page): Promise<MockApi> {
  const api: MockApi = { state: initial(), requests: [] };
  const s = api.state;

  const counts = (mapId: number) => {
    const layerIds = s.layers.filter((l) => l['map_id'] === mapId).map((l) => l['id']);
    return {
      layer_count: layerIds.length,
      feature_count: s.features.filter((f) => layerIds.includes(f['layer_id'])).length,
    };
  };
  const mapOut = (m: Json) => ({ ...m, ...counts(m['id'] as number) });
  const reply = (route: Route, body: unknown, status = 200, headers: Record<string, string> = {}) =>
    route.fulfill({
      status,
      contentType: 'application/json',
      headers,
      body: status === 204 ? '' : JSON.stringify(body),
    });

  await page.route(/^https?:\/\/(?!127\.0\.0\.1|localhost)/, (route) =>
    /tile\.openstreetmap\.org|tiles\.example\.org/.test(route.request().url())
      ? route.fulfill({
          status: 200,
          contentType: 'image/png',
          body: PNG,
          headers: { 'access-control-allow-origin': '*' },
        })
      : route.abort(),
  );

  await page.route('**/wp-json/sw-maps/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/wp-json/sw-maps/v1/', '');
    const method = request.method();
    const body = (request.postDataJSON() ?? {}) as Json;
    api.requests.push(`${method} ${path}`);
    const id = Number(/\/(\d+)/.exec(path)?.[1] ?? 0);
    const find = (list: Json[]) => list.find((x) => x['id'] === id);

    if (path === 'maps' && method === 'GET') {
      const q = (url.searchParams.get('search') ?? '').toLowerCase();
      const items = s.maps.filter((m) => String(m['title']).toLowerCase().includes(q)).map(mapOut);
      return reply(route, items, 200, {
        'X-WP-Total': String(items.length),
        'X-WP-TotalPages': '1',
      });
    }
    if (path === 'maps' && method === 'POST') {
      const map = {
        id: ++s.seq,
        slug: String(body['title']).toLowerCase().replace(/\W+/g, '-'),
        title: body['title'],
        description: '',
        status: 'draft',
        viewport: {
          center: [10, 51],
          zoom: 5,
          minZoom: 0,
          maxZoom: 19,
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
        created_at: now,
        updated_at: now,
      };
      s.maps.push(map);
      return reply(route, mapOut(map), 201);
    }
    if (/^maps\/\d+$/.test(path)) {
      const map = find(s.maps);
      if (!map)
        return reply(
          route,
          { code: 'swmap_not_found', message: 'Not found.', data: { status: 404 } },
          404,
        );
      if (method === 'GET') return reply(route, mapOut(map));
      if (method === 'PATCH') {
        for (const [k, v] of Object.entries(body))
          map[k] =
            k === 'viewport' || k === 'settings' ? { ...(map[k] as Json), ...(v as Json) } : v;
        return reply(route, mapOut(map));
      }
      if (method === 'DELETE') {
        s.maps = s.maps.filter((m) => m !== map);
        return reply(route, null, 204);
      }
    }
    if (/^maps\/\d+\/duplicate$/.test(path)) {
      const source = find(s.maps)!;
      const copy = {
        ...structuredClone(source),
        id: ++s.seq,
        title: `${source['title']} (copy)`,
        status: 'draft',
      };
      s.maps.push(copy);
      return reply(route, mapOut(copy), 201);
    }
    if (/^maps\/\d+\/layers$/.test(path)) {
      if (method === 'GET')
        return reply(
          route,
          s.layers.filter((l) => l['map_id'] === id),
        );
      const layer = {
        id: ++s.seq,
        map_id: id,
        type: 'features',
        created_at: now,
        updated_at: now,
        style: {},
        ...body,
      };
      s.layers.push(layer);
      return reply(route, layer, 201);
    }
    if (/^layers\/\d+\/features$/.test(path)) {
      if (method === 'GET') {
        const items = s.features.filter((f) => f['layer_id'] === id);
        return reply(route, items, 200, {
          'X-WP-Total': String(items.length),
          'X-WP-TotalPages': '1',
        });
      }
      const feature = { id: ++s.seq, layer_id: id, external_id: null, updated_at: now, ...body };
      s.features.push(feature);
      return reply(route, feature, 201);
    }
    if (/^features\/\d+$/.test(path)) {
      const feature = find(s.features);
      if (!feature)
        return reply(
          route,
          { code: 'swmap_not_found', message: 'Not found.', data: { status: 404 } },
          404,
        );
      if (method === 'PATCH') Object.assign(feature, body);
      if (method === 'DELETE') {
        s.features = s.features.filter((f) => f !== feature);
        return reply(route, null, 204);
      }
      return reply(route, feature);
    }
    if (path === 'providers') return reply(route, s.providers);
    if (/^providers\/\d+$/.test(path) && method === 'PATCH') {
      const provider = find(s.providers)!;
      Object.assign(provider, body);
      return reply(route, provider);
    }
    if (/^providers\/\d+\/test$/.test(path))
      return reply(route, { ok: true, status: 200, message: 'Connection successful.' });
    if (path === 'settings') {
      if (method === 'PATCH') Object.assign(s.settings, body);
      return reply(route, s.settings);
    }
    if (path === 'status') {
      return reply(route, {
        plugin_version: 'dev',
        db_version: 1,
        db_expected: 1,
        tables: [],
        php_version: '8.3',
        wp_version: '6.9',
        db_server: 'MariaDB',
        multisite: false,
        environment: 'local',
        consent_api: false,
        settings: s.settings,
      });
    }
    return reply(
      route,
      { code: 'swmap_mock_unhandled', message: `${method} ${path}`, data: { status: 501 } },
      501,
    );
  });
  return api;
}

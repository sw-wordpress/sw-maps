import { describe, expect, it, vi } from 'vitest';
import { ApiClient, isApiError, normaliseError } from '@/api/client';
import type { ApiError } from '@/api/client';
import { api, createApi, setApiForTests } from '@/api';
import { configure } from './helpers';

function client(handler: (url: string, init: RequestInit) => Response | Promise<Response>) {
  const fetchImpl = vi.fn(async (url: string | URL | Request, init?: RequestInit) =>
    handler(String(url), init ?? {}),
  );
  return {
    fetchImpl,
    api: new ApiClient({
      restRoot: 'https://example.org/wp-json/sw-maps/v1/',
      nonce: 'abc',
      fetchImpl: fetchImpl as unknown as typeof fetch,
    }),
  };
}

const json = (body: unknown, init: ResponseInit = {}): Response =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });

describe('ApiClient', () => {
  it('sends nonce, JSON body and query parameters', async () => {
    const c = client(() => json({ ok: 1 }));
    await c.api.request('POST', 'maps', {
      body: { title: 'A' },
      query: { a: 1, b: undefined, c: '', d: null, e: false },
    });
    const [url, init] = c.fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://example.org/wp-json/sw-maps/v1/maps?a=1&e=false');
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('abc');
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"title":"A"}');
    expect(init.credentials).toBe('same-origin');
  });

  it('keeps existing query strings in the root and returns undefined for 204', async () => {
    const c = new ApiClient({
      restRoot: '/index.php?rest_route=/sw-maps/v1/',
      nonce: 'n',
      fetchImpl: (async (url: string) => {
        expect(url).toBe('/index.php?rest_route=/sw-maps/v1/maps?x=1'.replace('?x=1', '&x=1'));
        return new Response(null, { status: 204 });
      }) as unknown as typeof fetch,
    });
    expect(await c.request('DELETE', 'maps', { query: { x: 1 } })).toBeUndefined();
  });

  it('reads pagination headers', async () => {
    const c = client(() =>
      json([{ id: 1 }, { id: 2 }], {
        headers: { 'Content-Type': 'application/json', 'X-WP-Total': '7', 'X-WP-TotalPages': '4' },
      }),
    );
    expect(await c.api.list('maps')).toEqual({
      items: [{ id: 1 }, { id: 2 }],
      total: 7,
      totalPages: 4,
    });
    const d = client(() => json([{ id: 1 }]));
    expect(await d.api.list('maps')).toEqual({ items: [{ id: 1 }], total: 1, totalPages: 1 });
  });

  it('normalises WP_Error bodies with field details', async () => {
    const c = client(() =>
      json(
        {
          code: 'swmap_invalid_param',
          message: 'Invalid input.',
          data: { status: 400, details: [{ field: 'title', message: 'Required.' }, { bad: 1 }] },
        },
        { status: 400 },
      ),
    );
    const error = await c.api.request('POST', 'maps').catch((e: unknown) => e);
    expect(isApiError(error)).toBe(true);
    const e = error as ApiError;
    expect([e.code, e.status, e.details]).toEqual([
      'swmap_invalid_param',
      400,
      [{ field: 'title', message: 'Required.' }],
    ]);
    expect(e.fieldMessage('title')).toBe('Required.');
    expect(e.fieldMessage('other')).toBeUndefined();
  });

  it('falls back for non JSON error bodies, network errors and aborts', async () => {
    const html = client(() => new Response('<html>', { status: 502 }));
    expect(await html.api.request('GET', 'x').catch((e: unknown) => e)).toMatchObject({
      code: 'swmap_http_error',
      status: 502,
    });
    const down = client(() => {
      throw new TypeError('offline');
    });
    expect(await down.api.request('GET', 'x').catch((e: unknown) => e)).toMatchObject({
      code: 'swmap_network_error',
    });
    const aborted = client(() => {
      throw new DOMException('aborted', 'AbortError');
    });
    const abortError = (await aborted.api.request('GET', 'x').catch((e: unknown) => e)) as ApiError;
    expect(abortError.isAborted).toBe(true);
    expect(normaliseError(500, { code: 5 })).toMatchObject({ code: 'swmap_http_error' });
    expect(isApiError(new Error('x'))).toBe(false);
  });
});

describe('typed endpoints', () => {
  it('maps every endpoint to the documented route', async () => {
    const calls: string[] = [];
    const c = client((url, init) => {
      calls.push(`${init.method} ${url.replace('https://example.org/wp-json/sw-maps/v1/', '')}`);
      return init.method === 'DELETE'
        ? new Response(null, { status: 204 })
        : json(
            (url.includes('layers/') && url.endsWith('features')) ||
              url.endsWith('/layers') ||
              url.endsWith('maps') ||
              url.endsWith('providers')
              ? []
              : {},
          );
    });
    const a = createApi(c.api);
    await a.maps.get(1);
    await a.maps.create({ title: 'x' });
    await a.maps.update(1, {});
    await a.maps.remove(1);
    await a.maps.duplicate(1);
    await a.maps.list({ search: 'q', per_page: 5 });
    await a.layers.forMap(1);
    await a.layers.create(1, {});
    await a.layers.update(2, {});
    await a.layers.remove(2);
    await a.features.page(2);
    await a.features.create(2, {});
    await a.features.update(3, {});
    await a.features.remove(3);
    await a.providers.list();
    await a.providers.create({});
    await a.providers.update(4, {});
    await a.providers.remove(4);
    await a.providers.test(4);
    await a.settings.get();
    await a.settings.update({ default_height: 500 });
    await a.status.get();
    expect(calls).toEqual([
      'GET maps/1',
      'POST maps',
      'PATCH maps/1',
      'DELETE maps/1',
      'POST maps/1/duplicate',
      'GET maps?search=q&per_page=5',
      'GET maps/1/layers',
      'POST maps/1/layers',
      'PATCH layers/2',
      'DELETE layers/2',
      'GET layers/2/features?page=1&per_page=500',
      'POST layers/2/features',
      'PATCH features/3',
      'DELETE features/3',
      'GET providers',
      'POST providers',
      'PATCH providers/4',
      'DELETE providers/4',
      'POST providers/4/test',
      'GET settings',
      'PATCH settings',
      'GET status',
    ]);
  });

  it('creates the shared instance from the PHP config and allows test injection', () => {
    configure();
    setApiForTests(null);
    const first = api();
    expect(api()).toBe(first);
    setApiForTests(null);
  });
});

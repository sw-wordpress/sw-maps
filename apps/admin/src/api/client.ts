export type QueryValue = string | number | boolean | null | undefined;
export type Query = Record<string, QueryValue>;

/** Normalised error for every failed request (HTTP, WP_Error or network). */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  /** Field level validation messages (`details` of the REST error). */
  readonly details: { field: string; message: string }[];

  constructor(
    code: string,
    message: string,
    status: number,
    details: { field: string; message: string }[] = [],
  ) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.details = details;
  }

  get isAborted(): boolean {
    return this.code === 'swmap_aborted';
  }

  fieldMessage(field: string): string | undefined {
    return this.details.find((d) => d.field === field || d.field.startsWith(`${field}.`))?.message;
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
}

export interface Paged<T> {
  items: T[];
  total: number;
  totalPages: number;
}

export interface ClientOptions {
  restRoot: string;
  nonce: string;
  fetchImpl?: typeof fetch;
}

interface WpErrorBody {
  code?: unknown;
  message?: unknown;
  data?: { status?: unknown; details?: unknown } | null;
}

function toDetails(value: unknown): { field: string; message: string }[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((d) => {
    const item = d as { field?: unknown; message?: unknown };
    return typeof item.field === 'string' && typeof item.message === 'string'
      ? [{ field: item.field, message: item.message }]
      : [];
  });
}

/** Turns any non-OK response body into an ApiError. Exported for tests. */
export function normaliseError(status: number, body: unknown): ApiError {
  if (body && typeof body === 'object') {
    const b = body as WpErrorBody;
    if (typeof b.code === 'string' && typeof b.message === 'string') {
      const dataStatus = typeof b.data?.status === 'number' ? b.data.status : status;
      return new ApiError(b.code, b.message, dataStatus, toDetails(b.data?.details));
    }
  }
  return new ApiError('swmap_http_error', `HTTP ${status}`, status);
}

export class ApiClient {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: ClientOptions) {
    this.fetchImpl = options.fetchImpl ?? ((...args) => fetch(...args));
  }

  private url(path: string, query?: Query): string {
    const base = this.options.restRoot + path.replace(/^\//, '');
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
    }
    const qs = params.toString();
    return qs === '' ? base : `${base}${base.includes('?') ? '&' : '?'}${qs}`;
  }

  private async send(method: string, path: string, opts: RequestOptions): Promise<Response> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-WP-Nonce': this.options.nonce,
    };
    let body: string | undefined;
    if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }
    let response: Response;
    try {
      response = await this.fetchImpl(this.url(path, opts.query), {
        method,
        headers,
        body,
        signal: opts.signal,
        credentials: 'same-origin',
      });
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw new ApiError('swmap_aborted', 'Request aborted', 0);
      }
      throw new ApiError('swmap_network_error', 'Network error', 0);
    }
    if (!response.ok) {
      let parsed: unknown;
      try {
        parsed = await response.json();
      } catch {
        parsed = null;
      }
      throw normaliseError(response.status, parsed);
    }
    return response;
  }

  async request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
    const response = await this.send(method, path, opts);
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
  }

  async list<T>(path: string, opts: RequestOptions = {}): Promise<Paged<T>> {
    const response = await this.send('GET', path, opts);
    const items = (await response.json()) as T[];
    const total = Number(response.headers.get('X-WP-Total') ?? items.length);
    const totalPages = Number(response.headers.get('X-WP-TotalPages') ?? 1);
    return { items, total, totalPages };
  }
}

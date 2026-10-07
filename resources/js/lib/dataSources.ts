import { xsrfToken } from '@/lib/session';

// Client of the Data sources API (Story 2.3): `/api/v1/admin/data-sources`. The server owns every rule (the Base URL,
// the allowlist, the headers, the limits and the ceilings); the client sends what was typed and shows the server's
// reason as a field error. The row's `revision` is sent back with an edit.
export const DATA_SOURCES_URL = '/api/v1/admin/data-sources';

export type DataSourceSortKey = 'name' | 'host' | 'auth_type';

export type DefaultHeader = { name: string; value: string };

export type Ceilings = {
    timeout_seconds: number | null;
    max_response_bytes: number | null;
    max_pages: number | null;
};

export type DataSource = {
    data_source_id: string;
    name: string;
    base_url: string;
    scheme: 'http' | 'https';
    host: string;
    port: number;
    auth_type: string;
    headers: DefaultHeader[];
    timeout_seconds: number | null;
    max_response_bytes: number | null;
    max_pages: number | null;
    live_capable: boolean;
    revision: number;
    // Placeholders the later stories fill: 'checking', null and 0.
    health: string;
    last_successful_call_at: string | null;
    blocks_using: number;
    created_at: string;
    updated_at: string;
};

export type DataSourceListMeta = {
    total: number;
    matched: number;
    sort: DataSourceSortKey;
    direction: 'asc' | 'desc';
    ceilings: Ceilings;
};

export type DataSourceList = { data: DataSource[]; meta: DataSourceListMeta };

export type DataSourceQuery = {
    q: string;
    sort: DataSourceSortKey;
    direction: 'asc' | 'desc';
};

export type OneDataSource = { data: DataSource; meta: { ceilings: Ceilings } };

// What the form sends. A limit is a digit string, or null for "use the platform setting".
export type DataSourceInput = {
    name: string;
    base_url: string;
    headers: DefaultHeader[];
    timeout_seconds: string | null;
    max_response_bytes: string | null;
    max_pages: string | null;
    live_capable: boolean;
    auth_type: 'none';
};

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 not in the Workspace, 409 a
// stale revision (with the `current` state), 422 with field `errors` and the `reasons`, 429 throttled.
export class DataSourceError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: OneDataSource | null = null,
        readonly requestId: string | null = null,
    ) {
        super(`data source request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: unknown;
    error?: { code?: string; request_id?: string };
    errors?: Record<string, string[]>;
    reasons?: Record<string, string>;
    current?: OneDataSource;
};

function isList(body: unknown): body is DataSourceList {
    const list = body as Partial<DataSourceList> | null;

    return !!list && Array.isArray(list.data) && !!list.meta;
}

function isOne(body: unknown): body is OneDataSource {
    const one = body as Partial<OneDataSource> | null;

    return (
        !!one &&
        !!one.data &&
        !Array.isArray(one.data) &&
        typeof one.data === 'object' &&
        !!one.meta
    );
}

async function call(
    method: 'GET' | 'POST' | 'PUT',
    path: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<Body> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'GET' ? {} : { 'X-XSRF-TOKEN': xsrfToken() }),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new DataSourceError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new DataSourceError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reasons ?? {},
            json && isOne(json.current) ? json.current : null,
            json?.error?.request_id ?? null,
        );
    }

    return json;
}

export async function fetchDataSources(
    query: DataSourceQuery,
    signal?: AbortSignal,
): Promise<DataSourceList> {
    const params = new URLSearchParams({
        sort: query.sort,
        direction: query.direction,
    });

    if (query.q.trim() !== '') {
        params.set('q', query.q.trim());
    }

    const body = await call(
        'GET',
        `${DATA_SOURCES_URL}?${params.toString()}`,
        undefined,
        signal,
    );

    // A malformed answer is a failed load, never an empty list.
    if (!isList(body)) {
        throw new Error('data sources response is malformed');
    }

    return body;
}

export async function fetchDataSource(
    id: string,
    signal?: AbortSignal,
): Promise<OneDataSource> {
    const body = await call(
        'GET',
        `${DATA_SOURCES_URL}/${encodeURIComponent(id)}`,
        undefined,
        signal,
    );

    if (!isOne(body)) {
        throw new Error('data source response is malformed');
    }

    return body;
}

export async function createDataSource(
    input: DataSourceInput,
): Promise<OneDataSource> {
    const body = await call('POST', DATA_SOURCES_URL, input);

    if (!isOne(body)) {
        throw new DataSourceError(500);
    }

    return body;
}

export async function updateDataSource(
    id: string,
    input: DataSourceInput,
    revision: number,
): Promise<OneDataSource> {
    const body = await call(
        'PUT',
        `${DATA_SOURCES_URL}/${encodeURIComponent(id)}`,
        { ...input, revision },
    );

    if (!isOne(body)) {
        throw new DataSourceError(500);
    }

    return body;
}

// The blur check on the Base URL: the allowlist and `require_https` only, so nothing is resolved or requested. It
// resolves when the URL may be used and throws the 422 (with its `reasons.base_url`) when it may not.
export async function checkBaseUrl(
    baseUrl: string,
    signal?: AbortSignal,
): Promise<void> {
    await call(
        'POST',
        `${DATA_SOURCES_URL}/check-url`,
        { base_url: baseUrl },
        signal,
    );
}

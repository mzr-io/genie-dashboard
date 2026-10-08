import { DATA_SOURCES_URL } from '@/lib/dataSources';
import { xsrfToken } from '@/lib/session';

// Client of the Endpoints API (Story 2.9): `/api/v1/admin/data-sources/{id}/endpoints`. The server owns every rule (the
// method, the path, the bindings, the headers and the body template); the client sends what was typed and shows the
// server's reason as a field error. The Endpoint's `revision` is sent back with an edit.
export const endpointsUrl = (dataSourceId: string): string =>
    `${DATA_SOURCES_URL}/${encodeURIComponent(dataSourceId)}/endpoints`;

export type Method = 'GET' | 'POST';

// Fixed value, Date Range from and to, Block Period Selector start and end. User-context bindings come in a later story.
export type Binding =
    | 'fixed'
    | 'date_range_from'
    | 'date_range_to'
    | 'period_start'
    | 'period_end';

export const BINDINGS: Binding[] = [
    'fixed',
    'date_range_from',
    'date_range_to',
    'period_start',
    'period_end',
];

// The text a bound (not fixed) row shows in place of a value: what it will resolve to when the Endpoint is fetched.
export const RESOLVED_TEXT: Record<Exclude<Binding, 'fixed'>, string> = {
    date_range_from: 'date_range.from',
    date_range_to: 'date_range.to',
    period_start: 'period.start',
    period_end: 'period.end',
};

export type BindingRow = {
    name: string;
    binding: Binding;
    value: string | null;
};

export type Param = BindingRow & { kind?: 'path' | 'query' | 'body' };

export type PathSegment =
    | { type: 'literal'; value: string }
    | { type: 'param'; name: string };

export type Endpoint = {
    endpoint_id: string;
    data_source_id: string;
    method: Method;
    path: string;
    path_ast: PathSegment[];
    params: Param[];
    headers: BindingRow[];
    // The template as JSON text, a parameter written {"$param": "name"}.
    body_template: string | null;
    read_only_query: boolean;
    revision: number;
    created_at: string;
    updated_at: string;
};

export type EndpointList = {
    data: Endpoint[];
    meta: { total: number; matched: number };
};

// What the form sends.
export type EndpointInput = {
    method: Method;
    path: string;
    params: BindingRow[];
    headers: BindingRow[];
    body_template: string | null;
    read_only_query: boolean;
    // The risk confirmation of a POST.
    confirm_read_only: boolean;
};

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 not in the Workspace, 409 a
// stale revision (with the `current` Endpoint), 422 with field `errors` and the `reasons`, 429 throttled.
export class EndpointError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: Endpoint | null = null,
        readonly requestId: string | null = null,
    ) {
        super(`endpoint request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: unknown;
    error?: { code?: string; request_id?: string };
    errors?: Record<string, string[]>;
    reasons?: Record<string, string>;
    current?: { data?: Endpoint };
};

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

        throw new EndpointError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new EndpointError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reasons ?? {},
            json?.current?.data ?? null,
            json?.error?.request_id ?? null,
        );
    }

    return json;
}

function isOne(body: Body): body is Body & { data: Endpoint } {
    return (
        !!body.data &&
        typeof body.data === 'object' &&
        !Array.isArray(body.data)
    );
}

export async function fetchEndpoints(
    dataSourceId: string,
    q: string,
    signal?: AbortSignal,
): Promise<EndpointList> {
    const query =
        q.trim() === '' ? '' : `?${new URLSearchParams({ q: q.trim() })}`;
    const body = await call(
        'GET',
        `${endpointsUrl(dataSourceId)}${query}`,
        undefined,
        signal,
    );

    // A malformed answer is a failed load, never an empty list.
    if (!Array.isArray(body.data) || !body.meta) {
        throw new Error('endpoints response is malformed');
    }

    return body as EndpointList;
}

export async function createEndpoint(
    dataSourceId: string,
    input: EndpointInput,
): Promise<Endpoint> {
    const body = await call('POST', endpointsUrl(dataSourceId), input);

    if (!isOne(body)) {
        throw new EndpointError(500);
    }

    return body.data;
}

export async function updateEndpoint(
    dataSourceId: string,
    endpointId: string,
    input: EndpointInput,
    revision: number,
): Promise<Endpoint> {
    const body = await call(
        'PUT',
        `${endpointsUrl(dataSourceId)}/${encodeURIComponent(endpointId)}`,
        { ...input, revision },
    );

    if (!isOne(body)) {
        throw new EndpointError(500);
    }

    return body.data;
}

// The `{name}` placeholders of a path template, in order; what the form adds a parameter row for.
export function pathPlaceholders(path: string): string[] {
    const names: string[] = [];

    for (const match of path.matchAll(
        /\/\{([A-Za-z][A-Za-z0-9_]{0,63})\}(?=\/|$)/g,
    )) {
        if (!names.includes(match[1])) {
            names.push(match[1]);
        }
    }

    return names;
}

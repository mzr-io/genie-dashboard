import { xsrfToken } from '@/lib/session';

// Client of the Data sources API (Story 2.3): `/api/v1/admin/data-sources`. The server owns every rule (the Base URL,
// the allowlist, the headers, the limits and the ceilings); the client sends what was typed and shows the server's
// reason as a field error. The row's `revision` is sent back with an edit.
export const DATA_SOURCES_URL = '/api/v1/admin/data-sources';

export type DataSourceSortKey = 'name' | 'host' | 'auth_type';

// A default header. A secret one has no value in what the server returns: only its name and the flag.
export type DefaultHeader = { name: string; value?: string; secret?: boolean };

export type PaginationStyle =
    | 'none'
    | 'page'
    | 'offset'
    | 'cursor'
    | 'link_header';

export const PAGINATION_STYLES: PaginationStyle[] = [
    'none',
    'page',
    'offset',
    'cursor',
    'link_header',
];

export type AuthType =
    | 'none'
    | 'api_key'
    | 'bearer'
    | 'basic'
    | 'oauth2_client_credentials';

// The credential slots of an auth type; a secret header's slot is `header:{lower-case name}`.
export const SECRET_SLOTS: Record<AuthType, string[]> = {
    none: [],
    api_key: ['api_key'],
    bearer: ['bearer_token'],
    basic: ['basic_username', 'basic_password'],
    oauth2_client_credentials: ['oauth_client_secret'],
};

// All the API says about a secret: whether it is set and when, never a value.
export type SecretStatus = { configured: boolean; updated_at: string | null };

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
    api_key_name: string | null;
    api_key_placement: 'header' | 'query' | null;
    // OAuth2 client credentials (Story 2.7): plain values, null for any other type. The client secret is a write-only slot.
    oauth_token_url: string | null;
    oauth_client_id: string | null;
    oauth_scope: string | null;
    headers: DefaultHeader[];
    // Absent in the list; on a single read the status of each slot in use.
    secrets?: Record<string, SecretStatus>;
    timeout_seconds: number | null;
    max_response_bytes: number | null;
    max_pages: number | null;
    live_capable: boolean;
    // How the API pages its answers (Story 2.11); everything but the style is null unless the style needs it.
    pagination_style: PaginationStyle;
    pagination_param: string | null;
    pagination_size_param: string | null;
    pagination_size: number | null;
    pagination_records_path: string | null;
    pagination_cursor_path: string | null;
    revision: number;
    // The soft lock's epoch (Story 2.8): sent back with an edit, with the lock token.
    lock_epoch?: number;
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
    // Pagination (Story 2.11): only what the style needs is sent.
    pagination_style: PaginationStyle;
    pagination_param?: string;
    pagination_size_param?: string;
    pagination_size?: string;
    pagination_records_path?: string;
    pagination_cursor_path?: string;
    auth_type: AuthType;
    api_key_name?: string;
    api_key_placement?: 'header' | 'query';
    oauth_token_url?: string;
    oauth_client_id?: string;
    oauth_scope?: string;
    // Only the slots being set or replaced; an absent slot stays as saved. Never kept anywhere but in this request.
    secrets?: Record<string, string>;
    // Needed when a secret value or the auth type changes.
    confirm_password?: string;
};

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 not in the Workspace, 409 a
// stale revision (with the `current` state), 423 the edit lock was lost (with the `current` state), 422 with field `errors` and the `reasons`, 429 throttled.
export class DataSourceError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: OneDataSource | null = null,
        readonly requestId: string | null = null,
        // On a 429 from a rate limit: the seconds until the next attempt may be made.
        readonly retryAfter: number | null = null,
    ) {
        super(`data source request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: unknown;
    error?: { code?: string; request_id?: string; retry_after?: number };
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

// The `Retry-After` header of a throttled answer, in whole seconds, when the body did not say.
function retryAfterHeader(response: Response): number | null {
    const value = response.headers?.get?.('Retry-After');

    return value && /^\d{1,6}$/.test(value) ? Number(value) : null;
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
            typeof json?.error?.retry_after === 'number'
                ? json.error.retry_after
                : retryAfterHeader(response),
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

// What an edit says about the soft lock (Story 2.8): the epoch it was granted and the token that holds it.
export type LockClaim = { epoch: number; token: string };

export async function updateDataSource(
    id: string,
    input: DataSourceInput,
    revision: number,
    claim?: LockClaim | null,
): Promise<OneDataSource> {
    const body = await call(
        'PUT',
        `${DATA_SOURCES_URL}/${encodeURIComponent(id)}`,
        {
            ...input,
            revision,
            ...(claim
                ? { lock_epoch: claim.epoch, lock_token: claim.token }
                : {}),
        },
    );

    if (!isOne(body)) {
        throw new DataSourceError(500);
    }

    return body;
}

// The blur check on the OAuth2 token URL (Story 2.7): the same rules, reported on `oauth_token_url`.
export async function checkTokenUrl(
    tokenUrl: string,
    signal?: AbortSignal,
): Promise<void> {
    await call(
        'POST',
        `${DATA_SOURCES_URL}/check-url`,
        { oauth_token_url: tokenUrl },
        signal,
    );
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

// ---- Test connection (Story 2.5) --------------------------------------------------------------------------------
// The test starts an Operation that `worker-connector` runs; the page polls it until it ends. What it shows is only
// what the summary holds: the user code, the status, the latency, the host and the request ID.
export const OPERATIONS_URL = '/api/v1/operations';

// How often the Operation is asked about, and how many failed reads in a row end the wait.
export const POLL_INTERVAL_MS = 1000;
// How long the page waits for a worker before it gives up (the server keeps the Operation for longer).
export const POLL_DEADLINE_MS = 90_000;

// No worker answered within the deadline.
export class PollTimeout extends Error {
    constructor() {
        super('no worker responded');
    }
}
const POLL_TOLERATED_FAILURES = 3;

export type OperationStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'stale'
    | 'expired';

// The things an Admin is told about a failed test; all but `auth-failed` (Story 2.7) and `too-many-pages` (Story 2.11), which are labels because the catalogue is closed, are message catalogue keys.
export type ConnectionTestCode =
    | 'host-not-allowlisted'
    | 'blocked-address'
    | 'fetch-failed'
    | 'not-json'
    | 'response-too-large'
    | 'auth-failed'
    | 'too-many-pages'
    // A Fetch as user (Story 2.13) had no value for a bound attribute or group (or one that cannot be sent): nothing was sent.
    | 'access.context_missing';

export type ConnectionTestSummary = {
    ok: boolean;
    status: number | null;
    latency_ms: number | null;
    code: ConnectionTestCode | null;
    reason: string | null;
    // The bytes read and the limit, for `response-too-large` only (Story 2.6).
    size_bytes?: number | null;
    limit_bytes?: number | null;
    host: string | null;
    request_id: string | null;
    // An Endpoint test (Story 2.10): the Endpoint revision that was tested, or, for a stale result, the one that replaced it.
    endpoint_revision?: number | null;
    // A paged Endpoint test (Story 2.11): the page that failed or was reached, and the pages fetched; null when not paged.
    page?: number | null;
    pages?: number | null;
    // A Fetch as user (Story 2.13): the attribute key ids (and `user_group`) without a value, comma separated; never a value.
    missing?: string | null;
};

export type OperationSummary = {
    id: string;
    kind: string;
    status: OperationStatus;
    result: ConnectionTestSummary | null;
    expires_at: string;
};

export function operationEnded(status: OperationStatus): boolean {
    return status !== 'queued' && status !== 'running';
}

// Starts a test of the form as it stands (`202` with the Operation). Nothing is saved; typed secrets travel in this one
// request only. Throws the 422 with its field errors, the 429 with `retryAfter`, the 503 when credentials cannot be sealed.
export async function startConnectionTest(
    input: DataSourceInput,
    dataSourceId?: string | null,
): Promise<{ operation_id: string; status: OperationStatus }> {
    const body = await call('POST', `${DATA_SOURCES_URL}/test-connection`, {
        ...input,
        ...(dataSourceId ? { data_source_id: dataSourceId } : {}),
    });
    const data = body.data as
        | { operation_id?: unknown; status?: unknown }
        | undefined;

    if (!data || typeof data.operation_id !== 'string') {
        throw new DataSourceError(500);
    }

    return {
        operation_id: data.operation_id,
        status: data.status as OperationStatus,
    };
}

export async function fetchOperation(
    id: string,
    signal?: AbortSignal,
): Promise<OperationSummary> {
    const body = await call(
        'GET',
        `${OPERATIONS_URL}/${encodeURIComponent(id)}`,
        undefined,
        signal,
    );
    const data = body.data as Partial<OperationSummary> | undefined;

    if (!data || typeof data.status !== 'string') {
        throw new DataSourceError(500);
    }

    return data as OperationSummary;
}

function wait(ms: number, signal?: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new DOMException('aborted', 'AbortError'));

            return;
        }

        const timer = setTimeout(() => {
            signal?.removeEventListener('abort', onAbort);
            resolve();
        }, ms);
        const onAbort = (): void => {
            clearTimeout(timer);
            reject(new DOMException('aborted', 'AbortError'));
        };

        signal?.addEventListener('abort', onAbort, { once: true });
    });
}

// Asks about the Operation until it ends. A read that fails for a moment (the network, a 5xx) is tried again a few times
// in a row; a refusal that will not change (401, 404) ends the wait at once.
export async function pollOperation(
    id: string,
    signal?: AbortSignal,
    intervalMs: number = POLL_INTERVAL_MS,
    deadlineMs: number = POLL_DEADLINE_MS,
): Promise<OperationSummary> {
    let failures = 0;
    const until = Date.now() + deadlineMs;

    for (;;) {
        if (Date.now() >= until) {
            throw new PollTimeout();
        }

        try {
            const operation = await fetchOperation(id, signal);

            failures = 0;

            if (operationEnded(operation.status)) {
                return operation;
            }
        } catch (error) {
            // A throttled read (429) is waited out, not counted: the deadline still ends the wait.
            if (
                signal?.aborted ||
                !(error instanceof DataSourceError) ||
                (error.status !== 429 &&
                    ((error.status !== 0 && error.status < 500) ||
                        ++failures >= POLL_TOLERATED_FAILURES))
            ) {
                throw error;
            }
        }

        await wait(intervalMs, signal);
    }
}

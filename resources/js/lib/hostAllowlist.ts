import { xsrfToken } from '@/lib/session';

// Client of the Workspace host allowlist (Story 2.1): `/api/v1/admin/host-allowlist`. The server owns every host
// rule, the sort whitelist and the list-level revision; the client names a column and a direction and sends back the
// revision it last saw.
export const HOST_ALLOWLIST_URL = '/api/v1/admin/host-allowlist';

export type HostSortKey = 'host' | 'scheme' | 'port' | 'added';
export type HostScheme = 'http' | 'https';

export type HostEntry = {
    entry_id: string;
    host: string;
    scheme: HostScheme;
    port: number;
    // The member's display name, resolved by the server; null when it cannot be resolved.
    added_by: string | null;
    added_at: string;
};

export type HostAllowlistMeta = {
    // The list's revision: a change is accepted only against the current one.
    revision: number;
    total: number;
    matched: number;
    sort: HostSortKey;
    direction: 'asc' | 'desc';
};

export type HostAllowlistPage = { data: HostEntry[]; meta: HostAllowlistMeta };

export type HostAllowlistQuery = {
    q: string;
    sort: HostSortKey;
    direction: 'asc' | 'desc';
};

export type DependentSource = { id: string; name: string };

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 an entry that is not in the
// Workspace, 409 a stale revision (with the `current` list), 422 with field `errors` and the `reasons`, 429 throttled.
export class HostAllowlistError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: HostAllowlistPage | null = null,
    ) {
        super(`host allowlist request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: { revision?: number };
    error?: { code?: string };
    errors?: Record<string, string[]>;
    reasons?: Record<string, string>;
    current?: HostAllowlistPage;
};

function isPage(body: unknown): body is HostAllowlistPage {
    const page = body as Partial<HostAllowlistPage> | null;

    return !!page && Array.isArray(page.data) && !!page.meta;
}

export async function fetchHostAllowlist(
    query: HostAllowlistQuery,
    signal?: AbortSignal,
): Promise<HostAllowlistPage> {
    const params = new URLSearchParams({
        sort: query.sort,
        direction: query.direction,
    });

    if (query.q.trim() !== '') {
        params.set('q', query.q.trim());
    }

    let response: Response;

    try {
        response = await fetch(`${HOST_ALLOWLIST_URL}?${params.toString()}`, {
            method: 'GET',
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new HostAllowlistError(0);
    }

    if (!response.ok) {
        throw new HostAllowlistError(response.status);
    }

    const body: unknown = await response.json();

    // A malformed answer is a failed load, never an empty list.
    if (!isPage(body)) {
        throw new Error('host allowlist response is malformed');
    }

    return body;
}

async function call(
    method: 'GET' | 'POST' | 'DELETE',
    path: string,
    body?: unknown,
): Promise<Body> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
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
    } catch {
        throw new HostAllowlistError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new HostAllowlistError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reasons ?? {},
            json && isPage(json.current) ? json.current : null,
        );
    }

    return json;
}

export type AddedHost = { entry: HostEntry; revision: number };

export async function addHost(input: {
    host: string;
    scheme: HostScheme;
    revision: number;
}): Promise<AddedHost> {
    const json = await call('POST', HOST_ALLOWLIST_URL, input);

    if (!json.data || typeof json.meta?.revision !== 'number') {
        throw new HostAllowlistError(500);
    }

    return { entry: json.data as HostEntry, revision: json.meta.revision };
}

// The list's new revision.
export async function removeHost(
    entryId: string,
    revision: number,
): Promise<number> {
    const json = await call(
        'DELETE',
        `${HOST_ALLOWLIST_URL}/${encodeURIComponent(entryId)}?revision=${revision}`,
    );

    if (typeof json.meta?.revision !== 'number') {
        throw new HostAllowlistError(500);
    }

    return json.meta.revision;
}

// The Data Sources that removing the entry would block on their next call.
export async function fetchDependents(
    entryId: string,
): Promise<DependentSource[]> {
    const json = await call(
        'GET',
        `${HOST_ALLOWLIST_URL}/${encodeURIComponent(entryId)}/dependents`,
    );

    if (!Array.isArray(json.data)) {
        throw new HostAllowlistError(500);
    }

    return json.data as DependentSource[];
}

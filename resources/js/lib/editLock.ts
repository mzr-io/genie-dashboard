import { DATA_SOURCES_URL, DataSourceError } from '@/lib/dataSources';
import { xsrfToken } from '@/lib/session';

// Client of the Data source soft lock (Story 2.8): `/api/v1/admin/data-sources/{id}/lock`. The server decides who
// holds the lock; the client only asks and shows. The heartbeat and the polls send `X-Background: 1`, so they never
// extend the auth session. No call carries a secret value: the lock token (and, for the unload beacon, the CSRF
// token) is the only thing in a body.
export type LockHolder = { name: string; since: string };

export type LockAnswer =
    // The soft lock is off (`edit_lock_ttl` unset): the form works as before, with its `revision` only.
    | { enabled: false }
    | {
          enabled: true;
          status: 'granted';
          token: string;
          epoch: number;
          ttl_seconds: number;
          flush_requested: boolean;
          csrf_token?: string;
      }
    | { enabled: true; status: 'held'; holder: LockHolder }
    | { enabled: true; status: 'waiting'; token: string }
    // Nobody holds the lock: a take-over poll never grants, so the caller acquires.
    | { enabled: true; status: 'free' }
    | { enabled: true; status: 'lost' }
    | {
          enabled: true;
          status: 'taken_over';
          flush_acknowledged: boolean;
          taken_over_by?: { name: string | null; at: string | null };
      };

export type Granted = Extract<LockAnswer, { status: 'granted' }>;

export function lockUrl(id: string): string {
    return `${DATA_SOURCES_URL}/${encodeURIComponent(id)}/lock`;
}

// Transient "busy" answers (503) are retried this many times, this far apart.
const RETRIES = 2;
const RETRY_MS = 500;

async function call(
    method: 'GET' | 'POST' | 'PUT',
    path: string,
    options: {
        body?: unknown;
        background?: boolean;
        signal?: AbortSignal;
        headers?: Record<string, string>;
    } = {},
): Promise<LockAnswer> {
    for (let attempt = 0; ; attempt++) {
        try {
            return await attemptCall(method, path, options);
        } catch (error) {
            if (
                !(error instanceof DataSourceError) ||
                error.status !== 503 ||
                attempt >= RETRIES ||
                options.signal?.aborted
            ) {
                throw error;
            }

            await new Promise((resolve) => setTimeout(resolve, RETRY_MS));
        }
    }
}

async function attemptCall(
    method: 'GET' | 'POST' | 'PUT',
    path: string,
    options: {
        body?: unknown;
        background?: boolean;
        signal?: AbortSignal;
        headers?: Record<string, string>;
    },
): Promise<LockAnswer> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            signal: options.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.background ? { 'X-Background': '1' } : {}),
                ...options.headers,
                ...(method === 'GET' ? {} : { 'X-XSRF-TOKEN': xsrfToken() }),
                ...(options.body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(options.body === undefined
                ? {}
                : { body: JSON.stringify(options.body) }),
        });
    } catch (error) {
        if (options.signal?.aborted) {
            throw error;
        }

        throw new DataSourceError(0);
    }

    let data: Record<string, unknown> | undefined;

    try {
        data = (
            (await response.json()) as { data?: Record<string, unknown> } | null
        )?.data;
    } catch {
        data = undefined;
    }

    // A malformed answer is a failed call, never a verdict about the lock.
    if (
        !response.ok ||
        !data ||
        typeof data.enabled !== 'boolean' ||
        (data.enabled && typeof data.status !== 'string')
    ) {
        throw new DataSourceError(response.ok ? 500 : response.status);
    }

    return data as unknown as LockAnswer;
}

// Takes the lock when it is free (or already this person's); otherwise says who holds it.
export function acquireLock(
    id: string,
    signal?: AbortSignal,
): Promise<LockAnswer> {
    return call('POST', lockUrl(id), { signal });
}

// Renews the TTL; the answer reports a pending flush request, or that the lock was taken over or lost.
export function heartbeatLock(
    id: string,
    token: string,
    signal?: AbortSignal,
): Promise<LockAnswer> {
    return call('PUT', lockUrl(id), {
        body: { token },
        background: true,
        signal,
    });
}

// "Take over editing": `waiting` (poll with the token it answers) or `granted` at once.
export function requestTakeover(
    id: string,
    signal?: AbortSignal,
): Promise<LockAnswer> {
    return call('POST', `${lockUrl(id)}/takeover`, { signal });
}

export function pollTakeover(
    id: string,
    token: string,
    signal?: AbortSignal,
): Promise<LockAnswer> {
    // The token goes in a header: URLs end up in access logs.
    return call('GET', `${lockUrl(id)}/takeover`, {
        background: true,
        signal,
        headers: { 'X-Lock-Token': token },
    });
}

// The holder confirms its flush: the take-over completes at once.
export function acknowledgeFlush(
    id: string,
    token: string,
    signal?: AbortSignal,
): Promise<LockAnswer> {
    return call('POST', `${lockUrl(id)}/flush`, { body: { token }, signal });
}

// Frees the lock when the tab closes or the page is left. A beacon outlives the page, and it cannot set headers, so the
// token and the CSRF token go in the body; without `sendBeacon` a keep-alive request sends the XSRF header instead.
export function releaseLock(
    id: string,
    token: string,
    csrf: string | null,
): void {
    const url = `${lockUrl(id)}/release`;

    try {
        if (
            csrf &&
            typeof navigator !== 'undefined' &&
            typeof navigator.sendBeacon === 'function'
        ) {
            const body = new Blob([JSON.stringify({ token, _token: csrf })], {
                type: 'application/json',
            });

            if (navigator.sendBeacon(url, body)) {
                return;
            }
        }

        void fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ token }),
        }).catch(() => {});
    } catch {
        // The lock expires on its own.
    }
}

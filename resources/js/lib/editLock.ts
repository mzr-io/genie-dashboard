import { DATA_SOURCES_URL, DataSourceError } from '@/lib/dataSources';
import { xsrfToken } from '@/lib/session';

// Client of the soft lock (Story 2.8; Story 3.6 shares it with Draft blocks): `{collection}/{id}/lock`, by default the Data source's. The server decides who
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

// The lock of one resource lives at `{collection}/{id}/lock`: Data sources (Story 2.8) and Draft blocks (Story 3.6) share the protocol.
export function lockUrl(
    id: string,
    collection: string = DATA_SOURCES_URL,
): string {
    return `${collection}/${encodeURIComponent(id)}/lock`;
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

export type EditLockClient = {
    acquire(id: string, signal?: AbortSignal): Promise<LockAnswer>;
    heartbeat(
        id: string,
        token: string,
        signal?: AbortSignal,
    ): Promise<LockAnswer>;
    requestTakeover(id: string, signal?: AbortSignal): Promise<LockAnswer>;
    pollTakeover(
        id: string,
        token: string,
        signal?: AbortSignal,
    ): Promise<LockAnswer>;
    acknowledgeFlush(
        id: string,
        token: string,
        signal?: AbortSignal,
    ): Promise<LockAnswer>;
    release(id: string, token: string, csrf: string | null): void;
};

// The client of one resource collection's lock routes.
export function lockClient(collection: string): EditLockClient {
    const url = (id: string): string => lockUrl(id, collection);

    return {
        // Takes the lock when it is free (or already this person's); otherwise says who holds it.
        acquire: (id, signal) => call('POST', url(id), { signal }),
        // Renews the TTL; the answer reports a pending flush request, or that the lock was taken over or lost.
        heartbeat: (id, token, signal) =>
            call('PUT', url(id), { body: { token }, background: true, signal }),
        // "Take over editing": `waiting` (poll with the token it answers) or `granted` at once.
        requestTakeover: (id, signal) =>
            call('POST', `${url(id)}/takeover`, { signal }),
        // The token goes in a header: URLs end up in access logs.
        pollTakeover: (id, token, signal) =>
            call('GET', `${url(id)}/takeover`, {
                background: true,
                signal,
                headers: { 'X-Lock-Token': token },
            }),
        // The holder confirms its flush: the take-over completes at once.
        acknowledgeFlush: (id, token, signal) =>
            call('POST', `${url(id)}/flush`, { body: { token }, signal }),
        release: (id, token, csrf) => release(url(id), token, csrf),
    };
}

export const dataSourceLockClient = lockClient(DATA_SOURCES_URL);
const dataSourceLock = dataSourceLockClient;

export const acquireLock: EditLockClient['acquire'] = (id, signal) =>
    dataSourceLock.acquire(id, signal);
export const heartbeatLock: EditLockClient['heartbeat'] = (id, token, signal) =>
    dataSourceLock.heartbeat(id, token, signal);
export const requestTakeover: EditLockClient['requestTakeover'] = (
    id,
    signal,
) => dataSourceLock.requestTakeover(id, signal);
export const pollTakeover: EditLockClient['pollTakeover'] = (
    id,
    token,
    signal,
) => dataSourceLock.pollTakeover(id, token, signal);
export const acknowledgeFlush: EditLockClient['acknowledgeFlush'] = (
    id,
    token,
    signal,
) => dataSourceLock.acknowledgeFlush(id, token, signal);

export function releaseLock(
    id: string,
    token: string,
    csrf: string | null,
): void {
    dataSourceLock.release(id, token, csrf);
}

// Frees the lock when the tab closes or the page is left. A beacon outlives the page, and it cannot set headers, so the
// token and the CSRF token go in the body; without `sendBeacon` a keep-alive request sends the XSRF header instead.
function release(lock: string, token: string, csrf: string | null): void {
    const url = `${lock}/release`;

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

// Client side of the session idle clock (Story 1.15): the status and extend endpoints.
export const SESSION_URL = '/api/v1/session';
export const SIGN_IN_URL = '/login';

export type SessionStatus = { remaining_seconds: number; area: string | null };

// The server answered 401: the session is already over.
export class SessionEnded extends Error {}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function call(
    path: string,
    method: 'GET' | 'POST',
    headers: Record<string, string>,
): Promise<SessionStatus> {
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...headers,
        },
    });

    if (response.status === 401) {
        throw new SessionEnded();
    }

    if (!response.ok) {
        throw new Error(`session request failed: ${response.status}`);
    }

    const body = (await response.json()) as Partial<SessionStatus> | null;

    // A malformed answer is a failed call, never a deadline.
    if (
        !body ||
        typeof body.remaining_seconds !== 'number' ||
        !Number.isFinite(body.remaining_seconds)
    ) {
        throw new Error('session response is malformed');
    }

    return body as SessionStatus;
}

// Polling is a background call: `X-Background: 1` keeps it from extending the idle session.
export function fetchSessionStatus(): Promise<SessionStatus> {
    return call(SESSION_URL, 'GET', { 'X-Background': '1' });
}

// User-initiated: this one extends the session.
export function extendSession(): Promise<SessionStatus> {
    return call(`${SESSION_URL}/extend`, 'POST', {
        'X-XSRF-TOKEN': xsrfToken(),
    });
}

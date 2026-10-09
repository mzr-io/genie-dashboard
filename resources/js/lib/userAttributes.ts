import { xsrfToken } from '@/lib/session';

// Client of the user attributes API (Story 2.12): the Workspace's catalogue of attribute keys
// (`/api/v1/admin/user-attributes`, `settings.manage`) and one member's values
// (`/api/v1/admin/members/{membership}/attributes`, `users.manage`). The server owns every rule: the key id and the
// value type never change, a label changes under the key's `revision`, and a value is checked by the key's type. A
// value goes only to the server and back to an Admin who may set it: never to a log, a toast or a URL.
export const USER_ATTRIBUTES_URL = '/api/v1/admin/user-attributes';
export const MEMBERS_URL = '/api/v1/admin/members';

export type AttributeValueType = 'text' | 'identifier' | 'integer';

export type AttributeKey = {
    id: string;
    key_id: string;
    label: string;
    value_type: AttributeValueType;
    revision: number;
    created_at: string;
    updated_at: string;
};

export type AttributeKeysPage = {
    data: AttributeKey[];
    meta: { total: number; matched: number };
};

export type MemberAttribute = {
    key_id: string;
    label: string;
    value_type: AttributeValueType;
    // The plain value, or null when none is set.
    value: string | null;
};

// A request the server refused (or that never arrived: status 0): 403 no permission or your own attributes, 404 a key
// or member that is not in the Workspace, 409 a stale revision (with the `current` key), 422 with field `errors` and
// `reasons`, 429 throttled, 503 the keys that seal values are unavailable.
export class UserAttributesError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: AttributeKey | null = null,
    ) {
        super(`user attributes request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: { total?: number; matched?: number };
    error?: { code?: string };
    errors?: Record<string, string[]>;
    reasons?: Record<string, string>;
    current?: AttributeKey;
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

        throw new UserAttributesError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new UserAttributesError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reasons ?? {},
            json?.current ?? null,
        );
    }

    return json;
}

export async function fetchAttributeKeys(
    q: string,
    signal?: AbortSignal,
): Promise<AttributeKeysPage> {
    const query =
        q.trim() === '' ? '' : `?${new URLSearchParams({ q: q.trim() })}`;
    const json = await call(
        'GET',
        `${USER_ATTRIBUTES_URL}${query}`,
        undefined,
        signal,
    );

    // A malformed answer is a failed load, never an empty list.
    if (!Array.isArray(json.data) || !json.meta) {
        throw new Error('user attributes response is malformed');
    }

    return {
        data: json.data as AttributeKey[],
        meta: {
            total: json.meta.total ?? 0,
            matched: json.meta.matched ?? 0,
        },
    };
}

export async function createAttributeKey(input: {
    key_id: string;
    label: string;
    value_type: AttributeValueType;
}): Promise<AttributeKey> {
    const json = await call('POST', USER_ATTRIBUTES_URL, input);

    if (!json.data) {
        throw new UserAttributesError(500);
    }

    return json.data as AttributeKey;
}

// Only the label changes: the key id and the type are fixed at creation.
export async function renameAttributeKey(
    keyId: string,
    label: string,
    revision: number,
): Promise<AttributeKey> {
    const json = await call(
        'PUT',
        `${USER_ATTRIBUTES_URL}/${encodeURIComponent(keyId)}`,
        { label, revision },
    );

    if (!json.data) {
        throw new UserAttributesError(500);
    }

    return json.data as AttributeKey;
}

export async function fetchMemberAttributes(
    membershipId: string,
    signal?: AbortSignal,
): Promise<MemberAttribute[]> {
    const json = await call(
        'GET',
        `${MEMBERS_URL}/${encodeURIComponent(membershipId)}/attributes`,
        undefined,
        signal,
    );

    if (!Array.isArray(json.data)) {
        throw new Error('member attributes response is malformed');
    }

    return json.data as MemberAttribute[];
}

// Sets the supplied keys (an absent key is unchanged); returns the key ids that changed.
export async function saveMemberAttributes(
    membershipId: string,
    values: Record<string, string>,
): Promise<string[]> {
    const json = await call(
        'PUT',
        `${MEMBERS_URL}/${encodeURIComponent(membershipId)}/attributes`,
        { values },
    );
    const changed = (json.data as { changed?: string[] } | undefined)?.changed;

    if (!Array.isArray(changed)) {
        throw new UserAttributesError(500);
    }

    return changed;
}

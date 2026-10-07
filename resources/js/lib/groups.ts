import { xsrfToken } from '@/lib/session';

// Client of the Workspace's groups (Story 1.23): `/api/v1/admin/groups`. The server owns the name rules, the sort
// whitelist and the uniqueness of names; the client names a column and a direction.
export const GROUPS_URL = '/api/v1/admin/groups';

export type GroupSortKey = 'name' | 'members' | 'created';

export type GroupMember = {
    membership_id: string;
    name: string;
    email: string;
    // A deactivated member keeps their place in the group.
    status: 'active' | 'deactivated';
};

export type Group = {
    group_id: string;
    name: string;
    member_count: number;
    members: GroupMember[];
    created_at: string;
    updated_at: string;
};

export type GroupsMeta = {
    // Every group of the Workspace, and the groups the search matches.
    total: number;
    matched: number;
    sort: GroupSortKey;
    direction: 'asc' | 'desc';
};

export type GroupsQuery = {
    q: string;
    sort: GroupSortKey;
    direction: 'asc' | 'desc';
};

export type GroupsPage = { data: Group[]; meta: GroupsMeta };

// A groups request the server refused (or that never arrived: status 0): 403 no permission, 404 a group or member
// that is not in the Workspace, 422 with field `errors` (the name), 429 throttled.
export class GroupRequestError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reason: string | null = null,
    ) {
        super(`groups request failed: ${status}`);
    }
}

type Body = {
    data?: Group;
    error?: { code?: string };
    errors?: Record<string, string[]>;
    reason?: string | null;
};

export async function fetchGroups(
    query: GroupsQuery,
    signal?: AbortSignal,
): Promise<GroupsPage> {
    const params = new URLSearchParams({
        sort: query.sort,
        direction: query.direction,
    });

    if (query.q.trim() !== '') {
        params.set('q', query.q.trim());
    }

    let response: Response;

    try {
        response = await fetch(`${GROUPS_URL}?${params.toString()}`, {
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

        throw new GroupRequestError(0);
    }

    if (!response.ok) {
        throw new GroupRequestError(response.status);
    }

    const body = (await response.json()) as Partial<GroupsPage> | null;

    // A malformed answer is a failed load, never an empty list.
    if (!body || !Array.isArray(body.data) || !body.meta) {
        throw new Error('groups response is malformed');
    }

    return body as GroupsPage;
}

async function call(
    method: 'POST' | 'PATCH' | 'DELETE',
    path: string,
    body?: unknown,
): Promise<Group | null> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new GroupRequestError(0);
    }

    if (response.status === 204) {
        return null;
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json?.data) {
        throw new GroupRequestError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reason ?? null,
        );
    }

    return json.data;
}

async function row(promise: Promise<Group | null>): Promise<Group> {
    const group = await promise;

    if (!group) {
        throw new GroupRequestError(500);
    }

    return group;
}

const id = encodeURIComponent;

export function createGroup(name: string): Promise<Group> {
    return row(call('POST', GROUPS_URL, { name }));
}

export function renameGroup(groupId: string, name: string): Promise<Group> {
    return row(call('PATCH', `${GROUPS_URL}/${id(groupId)}`, { name }));
}

export async function deleteGroup(groupId: string): Promise<void> {
    await call('DELETE', `${GROUPS_URL}/${id(groupId)}`);
}

export function addGroupMember(
    groupId: string,
    membershipId: string,
): Promise<Group> {
    return row(
        call(
            'POST',
            `${GROUPS_URL}/${id(groupId)}/members/${id(membershipId)}`,
            {},
        ),
    );
}

export function removeGroupMember(
    groupId: string,
    membershipId: string,
): Promise<Group> {
    return row(
        call(
            'DELETE',
            `${GROUPS_URL}/${id(groupId)}/members/${id(membershipId)}`,
        ),
    );
}

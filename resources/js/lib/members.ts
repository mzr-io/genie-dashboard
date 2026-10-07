// Client of the Workspace's user list (Story 1.20): `GET /api/v1/admin/members`. The server owns the whitelist
// of sort columns and the page-size cap; the client only names a column and a direction.
export const MEMBERS_URL = '/api/v1/admin/members';

export type MemberSortKey =
    | 'name'
    | 'email'
    | 'role'
    | 'status'
    | 'last_active';

export type Member = {
    kind: 'member' | 'invitation';
    // Exactly one of the two, by kind.
    membership_id?: string;
    invitation_id?: string;
    name: string;
    email: string;
    role: 'user' | 'admin';
    status: 'active' | 'invited' | 'deactivated';
    groups: string[];
    last_active_at: string | null;
};

export type MembersMeta = {
    per_page: number;
    next_cursor: string | null;
    // Every row of the Workspace, and the rows the search matches.
    total: number;
    matched: number;
    sort: MemberSortKey;
    direction: 'asc' | 'desc';
};

export type MembersQuery = {
    q: string;
    sort: MemberSortKey;
    direction: 'asc' | 'desc';
    cursor: string | null;
};

export type MembersPage = { data: Member[]; meta: MembersMeta };

// The list request failed with this HTTP status (401 and 419 mean the session is gone, 403 no permission).
export class MembersRequestError extends Error {
    constructor(readonly status: number) {
        super(`members request failed: ${status}`);
    }
}

export function memberKey(member: Member): string {
    return member.membership_id ?? member.invitation_id ?? member.email;
}

export async function fetchMembers(
    query: MembersQuery,
    signal?: AbortSignal,
): Promise<MembersPage> {
    const params = new URLSearchParams({
        sort: query.sort,
        direction: query.direction,
    });

    if (query.q.trim() !== '') {
        params.set('q', query.q.trim());
    }

    if (query.cursor) {
        params.set('cursor', query.cursor);
    }

    const response = await fetch(`${MEMBERS_URL}?${params.toString()}`, {
        method: 'GET',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new MembersRequestError(response.status);
    }

    const body = (await response.json()) as Partial<MembersPage> | null;

    // A malformed answer is a failed load, never an empty list.
    if (!body || !Array.isArray(body.data) || !body.meta) {
        throw new Error('members response is malformed');
    }

    return body as MembersPage;
}

import { xsrfToken } from '@/lib/session';

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
    // Story 1.22 (members only): the catalogue permissions held and the revision the editor must send back.
    permissions?: string[];
    revision?: number;
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

// ---- Invitations (Story 1.21) ----------------------------------------------------------------------------------

export const INVITATIONS_URL = '/api/v1/admin/invitations';

export type InvitePayload = {
    email: string;
    role: 'user' | 'admin';
    permissions: string[];
    confirm_password?: string;
};

// An invitation request the server refused (or that never arrived: status 0). The server's field errors are keyed
// by form field; `reason` is `pending` or `member` for an email that cannot be invited, and `invitationId` names the
// invitation a delivery failure or a pending duplicate refers to. Nothing here ever holds a token.
export class InvitationRequestError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reason: string | null = null,
        readonly invitationId: string | null = null,
    ) {
        super(`invitation request failed: ${status}`);
    }
}

type InvitationBody = {
    data?: Member;
    error?: { code?: string };
    errors?: Record<string, string[]>;
    reason?: string | null;
    invitation_id?: string | null;
};

async function invitationCall(
    method: 'POST' | 'DELETE',
    path: string,
    body?: unknown,
): Promise<Member | null> {
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
        throw new InvitationRequestError(0);
    }

    if (response.status === 204) {
        return null;
    }

    let json: InvitationBody | null = null;

    try {
        json = (await response.json()) as InvitationBody | null;
    } catch {
        json = null;
    }

    if (!response.ok) {
        throw new InvitationRequestError(
            response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reason ?? null,
            json?.invitation_id ?? null,
        );
    }

    return json?.data ?? null;
}

export async function inviteMember(payload: InvitePayload): Promise<Member> {
    const member = await invitationCall('POST', INVITATIONS_URL, payload);

    if (!member) {
        throw new InvitationRequestError(500);
    }

    return member;
}

export async function resendInvitation(id: string): Promise<Member> {
    const member = await invitationCall(
        'POST',
        `${INVITATIONS_URL}/${encodeURIComponent(id)}/resend`,
        {},
    );

    if (!member) {
        throw new InvitationRequestError(500);
    }

    return member;
}

export async function revokeInvitation(id: string): Promise<void> {
    await invitationCall(
        'DELETE',
        `${INVITATIONS_URL}/${encodeURIComponent(id)}`,
    );
}

// ---- Roles and permissions (Story 1.22) ------------------------------------------------------------------------

export const MEMBER_URL = '/api/v1/admin/members';

export type MemberUpdate = {
    revision: number;
    role?: 'user' | 'admin';
    permissions?: string[];
    confirm_password?: string;
};

// The member's current access, as a 409 returns it for a stale revision.
export type MemberAccess = {
    role: 'user' | 'admin';
    permissions: string[];
    revision: number;
};

// A change the server refused (or that never arrived: status 0): 403 `access.self_change_forbidden` or
// `access.permission_not_held`, 409 `access.last_users_manage_holder` or `access.revision_conflict` (with the
// member's `current` state), 422 with field errors, 429 throttled.
export class MemberUpdateError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly current: MemberAccess | null = null,
        readonly reason: string | null = null,
    ) {
        super(`member update failed: ${status}`);
    }
}

type UpdateBody = {
    data?: Member;
    error?: { code?: string };
    errors?: Record<string, string[]>;
    current?: MemberAccess;
    reason?: string | null;
};

export async function updateMember(
    membershipId: string,
    payload: MemberUpdate,
): Promise<Member> {
    let response: Response;

    try {
        response = await fetch(
            `${MEMBER_URL}/${encodeURIComponent(membershipId)}`,
            {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            },
        );
    } catch {
        throw new MemberUpdateError(0);
    }

    let json: UpdateBody | null = null;

    try {
        json = (await response.json()) as UpdateBody | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json?.data) {
        throw new MemberUpdateError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.current ?? null,
            json?.reason ?? null,
        );
    }

    return json.data;
}

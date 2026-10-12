import { WizardError, wizardCall } from '@/lib/blockWizard';
import type { FetchBody, SampleShape } from '@/lib/blockSample';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';
import type { ConnectionTestCode } from '@/lib/dataSources';

// Fetch as user in the Create block wizard (Story 3.17). The server resolves the member's ID, email, group and attributes itself and sends one
// request as that member; the response stays in the Operation's encrypted, short-lived blob, readable only by the Admin that started it. This
// module holds what the browser may know: who was chosen, when the result ends, and the value-free structure of the response (paths and types).
// No attribute value and no response body is ever here, and nothing in it is stored in sessionStorage or the autosave payload.

export type UserMember = {
    membership_id: string;
    name: string;
    email: string;
    groups: string[];
};

export type AsUserFailure = {
    code: ConnectionTestCode;
    reason: string | null;
    // The attribute key ids (and `user_group`) without a value, comma separated; never a value.
    missing: string | null;
    status: number | null;
    requestId: string | null;
    host: string | null;
};

export type AsUserPhase =
    | 'idle'
    | 'running'
    | 'ok'
    | 'failure'
    | 'expired'
    | 'stale';

export type AsUserState = {
    phase: AsUserPhase;
    // The Fetch as user result in use: the Operation, the member's name for the label, the Endpoint it fetched and when it ends.
    operationId: string | null;
    membershipId: string;
    name: string;
    dataSourceId: string | null;
    endpointId: string | null;
    // The key of the request it was taken with, to tell that the Draft's parameters have changed since.
    requestKey: string | null;
    expiresAt: string | null;
    // The value-free structure of the response, for the tree.
    shape: SampleShape | null;
    // Whether Step 4 previews as this member (otherwise the shared sample).
    using: boolean;
    // The result ended (it expired, or is no longer this Admin's to read): Step 4 went back to the shared sample and says so.
    ended: boolean;
    failure: AsUserFailure | null;
    refusal: string | null;
    wait: number | null;
};

export function emptyAsUser(): AsUserState {
    return {
        phase: 'idle',
        operationId: null,
        membershipId: '',
        name: '',
        dataSourceId: null,
        endpointId: null,
        requestKey: null,
        expiresAt: null,
        shape: null,
        using: false,
        ended: false,
        failure: null,
        refusal: null,
        wait: null,
    };
}

// What a preview needs to read the result: the Operation and the Endpoint it fetched.
export type AsUserRef = {
    as_user_operation: string;
    data_source_id: string;
    endpoint_id: string;
};

// The result can be previewed from now: it exists, has not ended, was taken with the Draft's current parameters, and Step 4 is set to use it.
export function asUserUsable(
    state: AsUserState,
    currentKey: string,
    now: number = Date.now(),
): boolean {
    return (
        state.operationId !== null &&
        state.using &&
        !state.ended &&
        state.requestKey === currentKey &&
        (state.expiresAt === null || Date.parse(state.expiresAt) > now)
    );
}

export function asUserRef(state: AsUserState): AsUserRef | null {
    return state.operationId !== null &&
        state.dataSourceId !== null &&
        state.endpointId !== null
        ? {
              as_user_operation: state.operationId,
              data_source_id: state.dataSourceId,
              endpoint_id: state.endpointId,
          }
        : null;
}

// Forgets the result (it ended, or the Admin left it). `ended` keeps the notice for Step 4 when the result ended on its own.
export function endAsUser(state: AsUserState, notice: boolean): void {
    state.operationId = null;
    state.shape = null;
    state.expiresAt = null;
    state.using = false;
    state.ended = notice;
    state.phase = 'idle';
}

const membersUrl = `${DRAFT_BLOCKS_URL.replace(/\/drafts$/, '')}/preview-as-user/members`;
const draftUrl = (draftId: string, tail: string): string =>
    `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/sample${tail}`;

// The active members a Fetch as user can target (`search` narrows them). Throws the 403 without `data.preview_as_user`.
export async function fetchUserMembers(
    search = '',
    signal?: AbortSignal,
): Promise<UserMember[]> {
    const query =
        search.trim() === ''
            ? ''
            : `?${new URLSearchParams({ search: search.trim() })}`;
    const body = await wizardCall(
        'GET',
        `${membersUrl}${query}`,
        undefined,
        signal,
    );
    const data = body.data as { members?: UserMember[] } | undefined;

    if (!data || !Array.isArray(data.members)) {
        throw new WizardError(500);
    }

    return data.members;
}

export type StartedAsUser = {
    operation_id: string;
    status: string;
    expires_at: string;
};

// Starts the Fetch as user (`202` with the Operation to poll). The body names the member and the request's non-bound choices only: a value for a
// user-bound name has no field. Throws the 403, 404, 422 (a value that does not fit) and 429 (`retry_after` in its body).
export async function startUserFetch(
    draftId: string,
    membershipId: string,
    body: FetchBody,
): Promise<StartedAsUser> {
    const answer = await wizardCall(
        'POST',
        draftUrl(draftId, '/fetch-as-user'),
        {
            membership: membershipId,
            data_source_id: body.data_source_id,
            endpoint_id: body.endpoint_id,
            bindings: body.bindings,
            default_date_range: body.default_date_range,
        },
    );
    const data = answer.data as Partial<StartedAsUser> | undefined;

    if (!data || typeof data.operation_id !== 'string') {
        throw new WizardError(500);
    }

    return data as StartedAsUser;
}

export type UserSampleRead = {
    status: number;
    latency_ms: number;
    expires_at: string;
    shape: SampleShape;
};

// The value-free structure of a finished Fetch as user, or null when it is gone.
export async function fetchUserShape(
    draftId: string,
    ref: AsUserRef,
    signal?: AbortSignal,
): Promise<UserSampleRead | null> {
    const query = new URLSearchParams({
        data_source_id: ref.data_source_id,
        endpoint_id: ref.endpoint_id,
    });

    try {
        const answer = await wizardCall(
            'GET',
            `${draftUrl(draftId, `/as-user/${encodeURIComponent(ref.as_user_operation)}`)}?${query}`,
            undefined,
            signal,
        );
        const data = answer.data as Partial<UserSampleRead> | undefined;

        return data && data.shape && typeof data.expires_at === 'string'
            ? (data as UserSampleRead)
            : null;
    } catch (error) {
        if (error instanceof WizardError && error.status === 404) {
            return null;
        }

        throw error;
    }
}

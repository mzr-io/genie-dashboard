import { lockClient } from '@/lib/editLock';
import type { LockAnswer } from '@/lib/editLock';
import { xsrfToken } from '@/lib/session';

// Client of the Draft blocks API (Story 3.3): `/api/v1/admin/blocks/drafts`. The server owns every rule; the list page reads the
// Drafts and discards one. Saving a Draft (compare-and-set on `revision` and `lock_epoch`) is the wizard's autosave, Story 3.5.
export const DRAFT_BLOCKS_URL = '/api/v1/admin/blocks/drafts';

// Where a row opens the wizard (Story 3.4 builds it; until then the Create block page answers). The step is the one the Draft was left on.
export const WIZARD_URL = '/admin/blocks/create';

export type DraftBlock = {
    draft_id: string;
    block_id: string;
    name: string;
    // The Block Type key, such as `kpi-card`.
    type: string;
    category: string | null;
    // The prospective version label, "1.0" for a first Draft.
    version: string;
    state: 'draft';
    revision: number;
    lock_epoch: number;
    // The wizard step the Draft was left on (1 to 5).
    step: number;
    owner_membership_id: string;
    // The owner's display name; null when it could not be resolved.
    owner: string | null;
    created_at: string;
    updated_at: string;
};

export type DraftBlockList = {
    data: DraftBlock[];
    meta: { total: number; matched: number };
};

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 not there (or already discarded), 429 throttled.
export class DraftBlockError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
    ) {
        super(`draft block request failed: ${status}`);
    }
}

type Body = { error?: { code?: string } };

async function call(
    method: 'GET' | 'DELETE',
    path: string,
    signal?: AbortSignal,
    payload?: unknown,
): Promise<unknown> {
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
                ...(payload === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new DraftBlockError(0);
    }

    let json: unknown = null;

    try {
        json = await response.json();
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new DraftBlockError(
            response.ok ? 500 : response.status,
            (json as Body | null)?.error?.code ?? null,
        );
    }

    return json;
}

function isList(body: unknown): body is DraftBlockList {
    const list = body as Partial<DraftBlockList> | null;

    return !!list && Array.isArray(list.data) && !!list.meta;
}

// The Drafts of the Workspace that match the search, newest edit first.
export async function fetchDraftBlocks(
    search: string,
    signal?: AbortSignal,
): Promise<DraftBlockList> {
    const query =
        search.trim() === '' ? '' : `?q=${encodeURIComponent(search)}`;
    const body = await call('GET', `${DRAFT_BLOCKS_URL}${query}`, signal);

    if (!isList(body)) {
        throw new DraftBlockError(500);
    }

    return body;
}

// Discards a Draft: the server hides it at once; its rows are deleted afterwards.
// With the soft lock on (Story 3.6) the server refuses a discard that does not carry the lock claim (423): the list takes the lock for
// the moment, discards with its token and epoch, and lets go. A Draft someone else is editing stays (the 423 is rethrown).
export async function discardDraftBlock(id: string): Promise<void> {
    const url = `${DRAFT_BLOCKS_URL}/${encodeURIComponent(id)}`;

    try {
        await call('DELETE', url);

        return;
    } catch (error) {
        if (!(error instanceof DraftBlockError) || error.status !== 423) {
            throw error;
        }
    }

    const client = lockClient(DRAFT_BLOCKS_URL);
    let answer: LockAnswer;

    try {
        answer = await client.acquire(id);
    } catch {
        throw new DraftBlockError(423);
    }

    if (!answer.enabled || answer.status !== 'granted') {
        throw new DraftBlockError(423);
    }

    try {
        await call('DELETE', url, undefined, {
            lock_token: answer.token,
            lock_epoch: answer.epoch,
        });
    } catch (error) {
        client.release(id, answer.token, answer.csrf_token ?? null);

        throw error;
    }
}

// The wizard address of a Draft, opened at its step. Built from the ID as text, never from markup.
export function draftWizardUrl(draft: DraftBlock): string {
    return `${WIZARD_URL}?draft=${encodeURIComponent(draft.draft_id)}&step=${draft.step}`;
}

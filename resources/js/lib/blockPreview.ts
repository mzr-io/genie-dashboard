import type { KpiCardPayload } from '@block-types/kpi-card/v1/payload';
import type { AsUserRef } from '@/lib/blockUserSample';
import { WizardError, wizardCall } from '@/lib/blockWizard';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';

// The live preview client (Story 3.14, UI stage 4): renders the Draft's sample through the Query Plan engine
// (`POST .../preview`, Story 3.10) and keeps only the newest answer. The server owns every rule; this file asks, debounces, drops stale
// answers and names a refusal. It never writes, and a background request never extends the idle session.

export const PREVIEW_DEBOUNCE_MS = 400;

export type PreviewSlot = {
    // The slot's value as the engine took it (a decimal string for a measure), or null when it is unavailable.
    value: string | null;
    path: string | null;
    unavailable?: string;
};

export type PreviewResult = {
    payload: KpiCardPayload;
    unavailable: Record<string, string>;
    slots: Record<string, PreviewSlot>;
    sample_revision: number | null;
    draft_revision: number | null;
    plan_stale: boolean;
    // Story 5.1: the rows before the transform steps and after each of them; null while the Draft has none.
    rows?: { input: number; steps: number[] } | null;
    // Story 3.17: set when it was computed from a Fetch as user result: when that result ends.
    as_user_expires_at?: string | null;
};

export type PreviewProblemKind =
    // The Draft has no plan yet: the preview is empty and the headline placeholder shows.
    | 'no_plan'
    | 'no_sample'
    // A numeric guard ended the run: no partial render.
    | 'guard'
    // A `pending_input` setting is not configured; `setting` names it.
    | 'setting'
    // The per-membership preview limit: the last good preview stays; `retryAfter` is in seconds.
    | 'throttled'
    // The server or the network could not answer.
    | 'unavailable'
    // The session ended (401, 419): the page handles it like any other step.
    | 'session'
    // Story 3.17: the Fetch as user result is gone (expired, or not this Admin's): the shared sample is shown again.
    | 'as_user_ended'
    // Story 3.17: the Admin no longer holds `data.preview_as_user`.
    | 'as_user_denied'
    // Story 3.17: the result is for another Data Source or Endpoint than the Draft's.
    | 'as_user_mismatch'
    | 'failed';

export type PreviewProblem = {
    kind: PreviewProblemKind;
    setting?: string;
    retryAfter?: number | null;
    status?: number;
};

const AS_USER_DENIED = 'blocks.preview_as_user_denied';

const previewUrl = (draftId: string): string =>
    `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/preview`;

// Reads the answer's shape defensively: a body that is not a preview is a failure, never a blank card.
const plain = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

function asResult(data: unknown): PreviewResult {
    const body = data as Record<string, unknown> | null | undefined;

    if (
        !plain(body) ||
        !plain(body.payload) ||
        typeof body.payload.state !== 'string' ||
        (body.slots !== undefined && !plain(body.slots)) ||
        (body.unavailable !== undefined && !plain(body.unavailable))
    ) {
        throw new WizardError(500);
    }

    return {
        payload: body.payload as unknown as KpiCardPayload,
        unavailable: (body.unavailable ?? {}) as PreviewResult['unavailable'],
        slots: (body.slots ?? {}) as PreviewResult['slots'],
        sample_revision: (body.sample_revision as number | null) ?? null,
        draft_revision: (body.draft_revision as number | null) ?? null,
        plan_stale: body.plan_stale === true,
        as_user_expires_at: plain(body.as_user)
            ? ((body.as_user.expires_at as string | null | undefined) ?? null)
            : null,
    };
}

// With `asUser` (Story 3.17) the same plan runs over the Admin's own Fetch as user result instead of the shared sample.
export async function fetchPreview(
    draftId: string,
    signal?: AbortSignal,
    asUser: AsUserRef | null = null,
): Promise<PreviewResult> {
    let answer;

    try {
        answer = await wizardCall(
            'POST',
            previewUrl(draftId),
            asUser ?? undefined,
            signal,
            true,
        );
    } catch (error) {
        if (
            asUser !== null &&
            error instanceof WizardError &&
            error.status === 403
        ) {
            throw new WizardError(403, AS_USER_DENIED);
        }

        throw error;
    }

    return asResult(answer.data);
}

// Names a refusal. A cancelled request is not a problem (the caller drops it).
export function previewProblem(error: unknown): PreviewProblem {
    if (!(error instanceof WizardError)) {
        return { kind: 'failed' };
    }

    if (error.status === 401 || error.status === 419) {
        return { kind: 'session', status: error.status };
    }

    // Only a 403 on a preview that asked for a Fetch as user result (marked by `fetchPreview`) is the permission going away.
    if (error.status === 403 && error.code === AS_USER_DENIED) {
        return { kind: 'as_user_denied', status: 403 };
    }

    if (error.status === 429) {
        const wait = Number(error.body.retry_after);

        return {
            kind: 'throttled',
            retryAfter: Number.isFinite(wait) && wait > 0 ? wait : null,
        };
    }

    if (error.status === 422) {
        if (error.code === 'mapping.no_plan') {
            return { kind: 'no_plan' };
        }

        if (error.code === 'mapping.no_sample') {
            return { kind: 'no_sample' };
        }

        if (error.code === 'blocks.preview_as_user_mismatch') {
            return { kind: 'as_user_mismatch' };
        }

        if (error.code === 'blocks.preview_as_user_ended') {
            return { kind: 'as_user_ended' };
        }

        if (error.code === 'mapping.guard_exceeded') {
            return { kind: 'guard' };
        }

        if (error.code === 'mapping.setting_not_configured') {
            const setting = error.body.setting;

            return {
                kind: 'setting',
                setting: typeof setting === 'string' ? setting : undefined,
            };
        }

        return { kind: 'failed' };
    }

    return error.status === 0 || error.status >= 500
        ? { kind: 'unavailable' }
        : { kind: 'failed' };
}

export type PreviewRunner = {
    // Asks for a preview: debounced unless `immediate`. Calls in a burst collapse into one.
    request: (immediate?: boolean) => void;
    // Drops the pending call and ignores the answer of the one in flight.
    cancel: () => void;
};

// One runner per preview pane: a debounce and a sequence counter, so only the newest request's answer is ever delivered and an older one that
// lands late is discarded.
export function createPreviewRunner(options: {
    load: (signal: AbortSignal) => Promise<PreviewResult>;
    onStart?: () => void;
    onResult: (result: PreviewResult) => void;
    onProblem: (problem: PreviewProblem) => void;
    delay?: number;
}): PreviewRunner {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let seq = 0;
    let controller: AbortController | null = null;

    function drop(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        controller?.abort();
        controller = null;
    }

    async function run(): Promise<void> {
        timer = null;

        const mine = (seq += 1);
        const own = new AbortController();

        controller?.abort();
        controller = own;
        options.onStart?.();

        try {
            const result = await options.load(own.signal);

            if (mine === seq) {
                options.onResult(result);
            }
        } catch (error) {
            if (mine === seq && !own.signal.aborted) {
                options.onProblem(previewProblem(error));
            }
        }
    }

    return {
        request(immediate = false) {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }

            if (immediate) {
                void run();

                return;
            }

            timer = setTimeout(
                () => void run(),
                options.delay ?? PREVIEW_DEBOUNCE_MS,
            );
        },
        cancel() {
            seq += 1;
            drop();
        },
    };
}

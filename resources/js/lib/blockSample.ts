import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';
import { WizardError, wizardCall } from '@/lib/blockWizard';

// The Sample Response of wizard Step 2 (Story 3.8, the paste half). The text is judged by the server with the platform's lossless JSON
// decoder (a number keeps every digit; the browser's own parser would round it), so this module never calls `JSON.parse` on a sample. It
// asks the server to check a text, to store the accepted one and to give it back when the Draft reopens. The text lives in server state
// only: never in sessionStorage, a log or the autosave payload.

export type SampleMode = 'fetch' | 'paste';

export type SampleNode = {
    id: number;
    parent: number | null;
    depth: number;
    key: string | null;
    // The stored RFC 9535 subset (`$.data.monthly[*].revenue`) and the dotted display form (`data.monthly[].revenue`).
    path: string;
    display: string;
    type: string;
    example: string | null;
    count: number | null;
};

export type SampleTable = {
    columns: { key: string; display: string }[];
    rows: string[][];
    total: number;
};

export type SampleShape = {
    records: { path: string; display: string; count: number } | null;
    scalars: number;
    arrays: number;
    objects: number;
    // Only on a stored sample, not on a check.
    nodes?: SampleNode[];
    tables?: Record<string, SampleTable>;
};

export type SampleReason =
    | 'not_json'
    | 'too_large'
    | 'too_deep'
    | 'limit_not_configured';

export type SampleCheck = {
    valid: boolean;
    size_bytes: number;
    reason: SampleReason | null;
    line: number | null;
    column: number | null;
    problem: string | null;
    limit: number | null;
    setting: 'max_bytes' | 'depth_limit' | null;
    shape: SampleShape | null;
};

export type StoredSample = SampleCheck & {
    origin: 'pasted';
    text: string;
    pasted_at: string;
    expires_at: string;
    contains_personal_data: boolean;
};

// Why a store was refused, beyond the reasons of a check.
export type StoreReason =
    | SampleReason
    | 'acknowledgement_required'
    | 'ttl_not_configured';

export type SampleState = {
    mode: SampleMode;
    // The textarea.
    text: string;
    // What the server last said about `checkedText`.
    check: SampleCheck | null;
    checkedText: string | null;
    // When the paste happened, for "Pasted 09:14" before the sample is stored.
    pastedAt: Date | null;
    // The accepted sample, as the server holds it.
    stored: StoredSample | null;
    // The Admin's "contains personal data" acknowledgement for the text being accepted.
    ack: boolean;
    checking: boolean;
    saving: boolean;
    // A request that did not answer, or a store the server refused, in words.
    problem:
        | StoreReason
        | 'check_failed'
        | 'store_failed'
        | 'locked'
        | 'unavailable'
        | null;
    restoreFailed: boolean;
};

export function emptySample(): SampleState {
    return {
        mode: 'fetch',
        text: '',
        check: null,
        checkedText: null,
        pastedAt: null,
        stored: null,
        ack: false,
        checking: false,
        saving: false,
        problem: null,
        restoreFailed: false,
    };
}

const sampleUrl = (draftId: string, tail = ''): string =>
    `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/sample${tail}`;

export async function checkSample(
    draftId: string,
    text: string,
): Promise<SampleCheck> {
    const body = await wizardCall('POST', sampleUrl(draftId, '/check'), {
        text,
    });

    return body.data as SampleCheck;
}

// The Draft's sample, or null when it has none or it has ended.
export async function fetchSample(
    draftId: string,
): Promise<StoredSample | null> {
    const body = await wizardCall('GET', sampleUrl(draftId));
    const found = body.data as Partial<StoredSample> | null | undefined;

    // Anything that is not a sample (no text, no time) is no sample.
    return found &&
        typeof found.text === 'string' &&
        typeof found.pasted_at === 'string'
        ? (found as StoredSample)
        : null;
}

export type SampleClaim = { lock_epoch: number; lock_token?: string };

export async function storeSample(
    draftId: string,
    text: string,
    containsPersonalData: boolean,
    claim: SampleClaim,
): Promise<StoredSample> {
    const body = await wizardCall('PUT', sampleUrl(draftId), {
        text,
        contains_personal_data: containsPersonalData,
        ...claim,
    });

    return body.data as StoredSample;
}

// What a refused store says: the server's own reason, or null for another failure.
export function storeReasonOf(error: unknown): StoreReason | null {
    if (error instanceof WizardError && error.status === 422) {
        const reason = error.body.reason;

        return typeof reason === 'string' ? (reason as StoreReason) : null;
    }

    return null;
}

// The shape line of the valid message: "4 scalars, 1 array".
export function shapeText(shape: SampleShape): string {
    const plural = (n: number, one: string) =>
        `${n} ${n === 1 ? one : `${one}s`}`;

    return `${plural(shape.scalars, 'scalar')}, ${plural(shape.arrays, 'array')}`;
}

// "09:14" in the browser's own time zone.
export function clockText(when: Date | string | null): string {
    const date = typeof when === 'string' ? new Date(when) : when;

    if (date === null || Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}

// The caret position of a 1-based line and column in a text, counting columns in characters as the server does.
export function caretAt(text: string, line: number, column: number): number {
    const starts = [0];

    for (let i = 0; i < text.length; i += 1) {
        if (text[i] === '\n') {
            starts.push(i + 1);
        }
    }

    const start = starts[Math.min(Math.max(line, 1), starts.length) - 1];
    const end = text.indexOf('\n', start);
    const lineEnd = end === -1 ? text.length : end;
    let index = start;

    for (
        let seen = 1;
        seen < Math.max(column, 1) && index < lineEnd;
        seen += 1
    ) {
        // A pair of UTF-16 units is one character.
        const code = text.charCodeAt(index);

        index +=
            code >= 0xd800 && code <= 0xdbff && index + 1 < lineEnd ? 2 : 1;
    }

    return index;
}

// Why Continue waits: a pasted text that is not empty must have been checked, unchanged since, and found valid. 'invalid' is a fresh
// invalid answer; 'unchecked' covers no check yet, a stale one, a check still running and a check that failed.
export function sampleBlock(
    sample: SampleState,
): 'invalid' | 'unchecked' | null {
    if (sample.mode !== 'paste' || sample.text.trim() === '') {
        return null;
    }

    if (sample.check !== null && sample.checkedText === sample.text) {
        return sample.check.valid ? null : 'invalid';
    }

    return 'unchecked';
}

// The nodes the tree shows: a node is visible when every ancestor is expanded.
export function visibleNodes(
    nodes: SampleNode[],
    collapsed: ReadonlySet<number>,
): SampleNode[] {
    const hidden = new Set<number>();
    const shown: SampleNode[] = [];

    for (const node of nodes) {
        if (
            node.parent !== null &&
            (hidden.has(node.parent) || collapsed.has(node.parent))
        ) {
            hidden.add(node.id);

            continue;
        }

        shown.push(node);
    }

    return shown;
}

// Reads the Draft's saved sample back into the state when the Draft opens (or is reloaded after a take-over). What the Admin has already typed
// is kept: a restore never overwrites a text that is being edited.
export async function restoreSample(
    state: SampleState,
    draftId: string,
): Promise<void> {
    try {
        const found = await fetchSample(draftId);

        state.restoreFailed = false;
        state.stored = found;

        if (found !== null && state.text === '') {
            state.text = found.text;
            state.check = found;
            state.checkedText = found.text;
            state.pastedAt = new Date(found.pasted_at);
            state.mode = 'paste';
        }
    } catch {
        state.restoreFailed = true;
    }
}

import { WizardError, wizardCall } from '@/lib/blockWizard';
import type { SampleClaim } from '@/lib/blockSample';
import type { SampleNode, SampleTable, SampleState } from '@/lib/blockSample';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';

// The Map Data client (Story 3.11b, stage 1): the one consistent read of a Draft's mapping (`GET .../mapping`, with its ETag) and the two writes
// this stage uses (`PUT .../mapping/record-path` and `.../mapping/field-roles`), plus the state helpers the Roles section needs. The server owns
// every rule; a write carries the Draft's `draft_revision` and the edit-lock claim, and answers with the revision it stored.

export const MAPPING_ROLES = [
    'label',
    'value',
    'dimension',
    'measure',
    'time',
    'filter',
] as const;

export type MappingRole = (typeof MAPPING_ROLES)[number];

// One literal class string per role (text, fill and border from the role tokens), so the stylesheet sees every colour it needs. A role is
// never shown as a colour alone: whoever uses these also prints the role's name.
export const ROLE_TONES: Record<MappingRole, string> = {
    label: 'bg-role-label-fill text-role-label-text border-role-label-border',
    value: 'bg-role-value-fill text-role-value-text border-role-value-border',
    dimension:
        'bg-role-dimension-fill text-role-dimension-text border-role-dimension-border',
    measure:
        'bg-role-measure-fill text-role-measure-text border-role-measure-border',
    time: 'bg-role-time-fill text-role-time-text border-role-time-border',
    filter: 'bg-role-filter-fill text-role-filter-text border-role-filter-border',
};

export type RoleField = {
    path: string;
    display: string;
    type: string;
    example: string | null;
    role: MappingRole;
    confidence: 'high' | 'low';
    overridden: boolean;
    in_records: boolean;
    suggestion: { role: MappingRole } | null;
};

// What Undo needs to put a role change back exactly as it was: a role that was only inferred is cleared, not stored as an override.
export type RoleUndo = {
    path: string;
    field: string;
    role: MappingRole;
    now: MappingRole;
    overridden: boolean;
    confidence: 'high' | 'low';
    suggestion: RoleField['suggestion'];
};

export type RecordCandidate = {
    path: string;
    display: string;
    rows: number;
    columns: string[];
    depth: number;
    suggested: boolean;
    pagination_like: boolean;
};

export type RolesReport = {
    record_path: string | null;
    record_path_display: string | null;
    needs_choice: boolean;
    candidates: RecordCandidate[];
    fields: RoleField[];
};

export type ChecklistCounts = {
    auto_mapped: number;
    total: number;
    confirmed: number;
    needs_review: number;
    required_missing: number;
    type_mismatch: number;
};

export type SlotStateName =
    | 'auto_high'
    | 'auto_medium'
    | 'auto_low'
    | 'confirmed'
    | 'overridden'
    | 'optional_empty'
    | 'required_missing'
    | 'type_mismatch';

export type SlotConfidence = 'high' | 'medium' | 'low' | null;

export type ChecklistSlot = {
    slot: string;
    label: string;
    group: string;
    required: boolean;
    state: SlotStateName;
    value_type?: string;
    confidence?: SlotConfidence;
    reason?: string | null;
    suggestion?: { path: string; display: string } | null;
    path?: string | null;
    display?: string | null;
    example?: string | null;
};

export type Blocker = {
    slot: string;
    reason:
        | 'required_missing'
        | 'unconfirmed_low'
        | 'type_mismatch'
        | 'api_changed';
};

export type Checklist = {
    groups: { group: string; slots: ChecklistSlot[] }[];
    counts: ChecklistCounts;
    blockers: Blocker[];
    baseline: { path: string; display: string }[];
    api_changed: Record<string, unknown>[];
};

export type SampleStatus = 'ok' | 'expired' | 'invalid';

export type MappingRead = {
    checklist: Checklist | null;
    roles: RolesReport | null;
    slot_map: Record<string, unknown>;
    query_plan: unknown;
    draft_revision: number;
    sample_revision: number | null;
    plan_stale: boolean;
    sample: SampleStatus;
};

// What a mapping write answers: the checklist, the revisions it stored and the plan; a role write also says what it changed.
export type MappingWrite = Checklist & {
    draft_revision: number;
    sample_revision: number | null;
    query_plan: unknown;
    role_change?: { path: string; from: string; to: string };
};

const mappingUrl = (draftId: string, tail = ''): string =>
    `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/mapping${tail}`;

type ReadBody = {
    data?: MappingRead;
    error?: { code?: string };
    errors?: Record<string, string[]>;
};

export type ReadResult = {
    // Null when the server answered 304: what the caller holds is current.
    data: MappingRead | null;
    etag: string | null;
};

// Reads the mapping. `etag` is sent as `If-None-Match`; a 304 answers `data: null`. A background refresh sends `X-Background: 1`, so it never
// extends the idle session. (`wizardCall` cannot do this: it has no 304 and does not expose the ETag header.)
export async function fetchMapping(
    draftId: string,
    options: { etag?: string | null; background?: boolean } = {},
): Promise<ReadResult> {
    let response: Response;

    try {
        response = await fetch(mappingUrl(draftId), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.background ? { 'X-Background': '1' } : {}),
                ...(options.etag ? { 'If-None-Match': options.etag } : {}),
            },
        });
    } catch {
        throw new WizardError(0);
    }

    if (response.status === 304) {
        return { data: null, etag: options.etag ?? null };
    }

    let json: ReadBody | null = null;

    try {
        json = (await response.json()) as ReadBody;
    } catch {
        json = null;
    }

    if (!response.ok || !json?.data) {
        throw new WizardError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
        );
    }

    return {
        data: json.data,
        etag: response.headers?.get?.('ETag') ?? null,
    };
}

async function write(
    draftId: string,
    tail: string,
    body: Record<string, unknown>,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    const answer = await wizardCall('PUT', mappingUrl(draftId, tail), {
        ...body,
        draft_revision: revision,
        ...claim,
    });

    return answer.data as MappingWrite;
}

// The array of records the rows come from; null clears the Admin's choice (the suggested one applies again).
export function putRecordPath(
    draftId: string,
    recordPath: string | null,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    return write(
        draftId,
        '/record-path',
        { record_path: recordPath },
        revision,
        claim,
    );
}

// One field's role; null clears the override.
export function putFieldRole(
    draftId: string,
    path: string,
    role: MappingRole | null,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    return write(draftId, '/field-roles', { path, role }, revision, claim);
}

// Re-runs auto-map: confirmed and overridden slots are never changed.
export function postAutoMap(
    draftId: string,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    return post(draftId, '/auto-map', revision, claim);
}

// Confirms a Medium or Low slot as mapped.
export function postConfirm(
    draftId: string,
    slot: string,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    return post(
        draftId,
        `/slots/${encodeURIComponent(slot)}/confirm`,
        revision,
        claim,
    );
}

async function post(
    draftId: string,
    tail: string,
    revision: number,
    claim: SampleClaim,
): Promise<MappingWrite> {
    const answer = await wizardCall('POST', mappingUrl(draftId, tail), {
        draft_revision: revision,
        ...claim,
    });

    return answer.data as MappingWrite;
}

// ---- State helpers --------------------------------------------------------------------------------------------------

// A field whose role the heuristics suspect (a suggestion the Admin has not dismissed).
export function flaggedFields(
    fields: RoleField[],
    dismissed: ReadonlySet<string>,
): RoleField[] {
    return fields.filter(
        (field) => field.suggestion !== null && !dismissed.has(field.path),
    );
}

const BASELINE = /(^|[^a-z0-9])(previous|prior|baseline|last)([^a-z0-9]|$)/i;
const UNIT = /(currency|unit|symbol)/i;

// The qualifier after a scalar's role chip ("Value · main"): a Value is the baseline when its key says so, else the first one is the main one;
// a Label that names a currency or unit is the unit; a Time scalar is the as-of time. Null when there is nothing to add.
export function qualifierOf(
    field: RoleField,
    scalars: RoleField[],
): 'main' | 'baseline' | 'unit' | 'asOf' | null {
    const key =
        field.display
            .split(/[.[\]]+/)
            .filter(Boolean)
            .pop() ?? '';

    if (field.role === 'value') {
        if (BASELINE.test(key)) {
            return 'baseline';
        }

        const first = scalars.find(
            (candidate) =>
                candidate.role === 'value' &&
                !BASELINE.test(
                    candidate.display
                        .split(/[.[\]]+/)
                        .filter(Boolean)
                        .pop() ?? '',
                ),
        );

        return first?.path === field.path ? 'main' : null;
    }

    if (field.role === 'label' && UNIT.test(key)) {
        return 'unit';
    }

    return field.role === 'time' ? 'asOf' : null;
}

// The sample rows for the chosen record path (at most five), from the accepted sample's tables, and the field each column belongs to.
export type RolesTable = {
    path: string;
    columns: { key: string; display: string; field: RoleField | null }[];
    rows: string[][];
};

export const TABLE_ROWS = 5;

export function rolesTable(
    sample: SampleState['stored'],
    roles: RolesReport | null,
): RolesTable | null {
    const shape = sample?.shape;

    if (!shape?.nodes || !shape.tables || !roles?.record_path) {
        return null;
    }

    const arrayPath = roles.record_path.replace(/\[\*\]$/, '');
    const node = (shape.nodes as SampleNode[]).find(
        (n) => n.path === arrayPath,
    );
    const table: SampleTable | undefined =
        node === undefined ? undefined : shape.tables[String(node.id)];

    if (node === undefined || table === undefined) {
        return null;
    }

    return {
        path: roles.record_path_display ?? node.display,
        columns: table.columns.map((column) => ({
            ...column,
            field:
                roles.fields.find(
                    (field) => field.display === column.display,
                ) ?? null,
        })),
        rows: table.rows.slice(0, TABLE_ROWS),
    };
}

// The status bar's "needs review or required missing" figures: the only ones whose change is announced.
export function announceKey(counts: ChecklistCounts | null): string {
    return counts === null
        ? ''
        : `${counts.needs_review}:${counts.required_missing}`;
}

// ---- Slot checklist helpers (Story 3.12b) -----------------------------------------------------------------------------

export type SlotFilter = 'all' | 'review' | 'missing';

export const NEEDS_REVIEW_STATES: readonly string[] = [
    'auto_medium',
    'auto_low',
];
const MISSING_STATES: readonly string[] = ['required_missing', 'type_mismatch'];

export function needsReview(slot: ChecklistSlot): boolean {
    return NEEDS_REVIEW_STATES.includes(slot.state);
}

export function isMissing(slot: ChecklistSlot): boolean {
    return MISSING_STATES.includes(slot.state);
}

// The confidence word of a row: auto rows carry their own, an Admin's decision is certain, an unmapped slot has none.
export function confidenceOf(
    slot: ChecklistSlot,
): 'high' | 'medium' | 'low' | 'none' {
    // An Admin's decision is certain; the row says "Confirmed by you" or "Overridden" instead of showing a confidence badge.
    if (slot.state === 'confirmed' || slot.state === 'overridden') {
        return 'high';
    }

    if (
        slot.state === 'auto_high' ||
        slot.state === 'auto_medium' ||
        slot.state === 'auto_low'
    ) {
        return slot.confidence ?? 'none';
    }

    return 'none';
}

export function filterCounts(
    slots: ChecklistSlot[],
): Record<SlotFilter, number> {
    return {
        all: slots.length,
        review: slots.filter(needsReview).length,
        missing: slots.filter(isMissing).length,
    };
}

export function matchesFilter(
    slot: ChecklistSlot,
    filter: SlotFilter,
): boolean {
    return (
        filter === 'all' ||
        (filter === 'review' ? needsReview(slot) : isMissing(slot))
    );
}

// Low rows first, then the order the server gave; computed on load and on Re-run only, so a row never jumps when it is confirmed.
export function computeOrder(groups: Checklist['groups']): string[] {
    return groups.flatMap((group) =>
        [...group.slots]
            .sort(
                (a, b) =>
                    Number(b.state === 'auto_low') -
                    Number(a.state === 'auto_low'),
            )
            .map((slot) => slot.slot),
    );
}

// The groups with each group's slots in the held order; a slot the order does not know goes last.
export function applyOrder(
    groups: Checklist['groups'],
    order: string[],
): Checklist['groups'] {
    const rank = (key: string): number => {
        const at = order.indexOf(key);

        return at === -1 ? Number.MAX_SAFE_INTEGER : at;
    };

    return groups.map((group) => ({
        ...group,
        slots: [...group.slots].sort((a, b) => rank(a.slot) - rank(b.slot)),
    }));
}

// One slot that blocks Continue, with every reason the server gave for it. A slot the checklist does not list still blocks (`known` false).
export type BlockingSlot = {
    key: string;
    label: string;
    known: boolean;
    reasons: Blocker['reason'][];
};

// Which slots block Continue: the server's blockers, one entry per slot, in the order of the list (unknown slots last).
export function blockingSlots(
    checklist: Checklist,
    order: string[],
): BlockingSlot[] {
    const all = applyOrder(checklist.groups, order).flatMap((g) => g.slots);
    const found = new Map<string, BlockingSlot & { at: number }>();

    for (const blocker of checklist.blockers) {
        const at = all.findIndex((s) => s.slot === blocker.slot);
        const entry = found.get(blocker.slot) ?? {
            key: blocker.slot,
            label: at === -1 ? blocker.slot : all[at].label,
            known: at !== -1,
            reasons: [],
            at: at === -1 ? Number.MAX_SAFE_INTEGER : at,
        };

        if (!entry.reasons.includes(blocker.reason)) {
            entry.reasons.push(blocker.reason);
        }

        found.set(blocker.slot, entry);
    }

    return [...found.values()]
        .sort((a, b) => a.at - b.at)
        .map(({ at: _at, ...entry }) => entry);
}

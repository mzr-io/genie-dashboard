import { barV1, kpiCardV1, kpiChartV1, lineAreaV1 } from '@/types/block-types';
import type { BlockType, BlockTypeHeight } from '@/types/block-types';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';
import type { DraftBlock } from '@/lib/draftBlocks';
import { xsrfToken } from '@/lib/session';
import type { SourceConfig, SourceForm } from '@/lib/blockSource';
import { blockWizardLabels as labels } from '@/locales/labels';

// The Create block wizard's client (Story 3.4): the Draft's `config` (schema_version 1), the Step 1 rules that mirror the server's, and the
// calls to `/api/v1/admin/blocks/*`. The server owns every rule (a 422 carries field errors); the checks here only save a round trip.
export const CATEGORIES_URL = '/api/v1/admin/blocks/categories';
export const STEP_COUNT = 5;
export const NAME_MAX = 120;
export const DESCRIPTION_MAX = 500;

// The Block Types the registry lists (`GET /api/v1/block-types` serves the same generated shapes).
export const BLOCK_TYPES: BlockType[] = [
    kpiCardV1,
    lineAreaV1,
    kpiChartV1,
    barV1,
];

export type PeriodBehavior =
    | 'follow_dashboard'
    | 'own_selector'
    | 'not_date_filtered';

export type FooterConfig = {
    target: 'external' | 'expanded_view';
    label: string;
    url: string;
};

export type DisplayConfig = {
    width: number;
    height: BlockTypeHeight;
    show_header: boolean;
    show_subtitle: boolean;
    allow_resize: boolean;
    allow_minimize: boolean;
    allow_refresh: boolean;
    show_footer_action: boolean;
    // The footer action's target, label and link (Story 5.4); only used while `show_footer_action` is on.
    footer: FooterConfig;
    min_width: number;
    max_width: number;
    min_height: BlockTypeHeight;
    max_height: BlockTypeHeight;
    period: PeriodBehavior;
};

export type DraftConfig = {
    schema_version: 1;
    description?: string;
    // An icon key; absent means the Category's icon.
    icon?: string;
    display?: Partial<DisplayConfig>;
    slots?: Record<string, { required: boolean; mapping: null }>;
    // Step 2 (Story 3.7): the Data Source, Endpoint, parameter values, refresh interval and default date range.
    source?: SourceConfig;
};

export type BlockCategory = { id: string; name: string; archived: boolean };

// The wizard's own state (Story 3.5), kept in `draft_ui_state`: never part of the version's config.
export type WizardUiState = {
    acknowledged_warnings?: string[];
    slot_statuses?: Record<string, string>;
};

export type WizardDraft = DraftBlock & {
    category_id: string | null;
    config: DraftConfig;
    ui_state?: WizardUiState;
};

// Step 1's form state: the page owns it, the step edits it in place.
export type StepForm = {
    name: string;
    description: string;
    categoryId: string;
    type: string;
    // The icon key chosen for this block; null follows the Category.
    icon: string | null;
    display: DisplayConfig;
    // Step 2's form state (Story 3.7).
    source: SourceForm;
};

// The id of each Step 1 field's control, by the field key the errors use; the page focuses the first invalid one.
export const FIELD_IDS: Record<string, string> = {
    name: 'wizard-name',
    description: 'wizard-description',
    category_id: 'wizard-category',
    type: 'wizard-type',
    icon: 'wizard-icon',
    'display.width': 'wizard-width',
    'display.height': 'wizard-height',
    'display.min_width': 'wizard-min-width',
    'display.max_width': 'wizard-max-width',
    'display.min_height': 'wizard-min-height',
    'display.max_height': 'wizard-max-height',
    'display.period': 'wizard-period',
    'display.footer.target': 'wizard-footer-target',
    'display.footer.label': 'wizard-footer-label',
    'display.footer.url': 'wizard-footer-url',
};

export const HEIGHTS: BlockTypeHeight[] = ['small', 'medium', 'large'];

export function blockTypeFor(key: string): BlockType | null {
    return BLOCK_TYPES.find((type) => type.key === key) ?? null;
}

// The option text ("Medium (360 px)") and the plain name ("Medium") used in the size pill and the helper text.
export function heightLabel(height: string): string {
    return labels.heights[height] ?? height;
}

export function heightName(height: string): string {
    return heightLabel(height).replace(/ \(.*\)$/, '');
}

// The defaults of a Block Type (kpi-card: 3 columns, Small), with every option on except Allow minimize.
export function defaultDisplay(type: BlockType): DisplayConfig {
    return {
        width: type.size.default_width,
        height: type.size.default_height,
        show_header: true,
        show_subtitle: true,
        allow_resize: true,
        allow_minimize: false,
        allow_refresh: true,
        show_footer_action: false,
        footer: { target: 'external', label: '', url: '' },
        min_width: type.size.min_width,
        max_width: type.size.max_width,
        min_height: type.size.min_height,
        max_height: type.size.max_height,
        period: 'follow_dashboard',
    };
}

// What was saved over the defaults: a Draft saved before a field existed still opens with a full set of options.
export function mergedDisplay(
    type: BlockType,
    saved: Partial<DisplayConfig> | undefined,
): DisplayConfig {
    const base = defaultDisplay(type);

    return {
        ...base,
        ...saved,
        footer: { ...base.footer, ...saved?.footer },
    };
}

// "3 columns × Small", the pill under the preview and the summary of a completed step.
export function sizeText(
    display: Pick<DisplayConfig, 'width' | 'height'>,
): string {
    return labels.sizePill(display.width, heightName(display.height));
}

export type DisplayErrors = Partial<Record<keyof DisplayConfig, string>>;

// The size rules the registry sets, mirrored from the server so the allowed range shows before a save.
export function validateDisplay(
    display: DisplayConfig,
    type: BlockType,
): DisplayErrors {
    const { size } = type;
    const errors: DisplayErrors = {};
    const widthRange = labels.widthRange(size.min_width, size.max_width);
    const heightRange = labels.heightRange(
        heightName(size.min_height),
        heightName(size.max_height),
    );
    const inWidth = (value: number) =>
        Number.isInteger(value) &&
        value >= size.min_width &&
        value <= size.max_width;
    const at = (height: BlockTypeHeight) => HEIGHTS.indexOf(height);
    const inHeight = (value: BlockTypeHeight) =>
        at(value) >= at(size.min_height) && at(value) <= at(size.max_height);

    for (const key of ['width', 'min_width', 'max_width'] as const) {
        if (!inWidth(display[key])) {
            errors[key] = widthRange;
        }
    }

    for (const key of ['height', 'min_height', 'max_height'] as const) {
        if (!inHeight(display[key])) {
            errors[key] = heightRange;
        }
    }

    if (
        !errors.min_width &&
        !errors.max_width &&
        display.min_width > display.max_width
    ) {
        errors.min_width = labels.minAboveMax;
    }

    if (
        !errors.width &&
        !errors.min_width &&
        !errors.max_width &&
        (display.width < display.min_width || display.width > display.max_width)
    ) {
        errors.width = labels.outsideLimits;
    }

    if (
        !errors.min_height &&
        !errors.max_height &&
        at(display.min_height) > at(display.max_height)
    ) {
        errors.min_height = labels.minAboveMax;
    }

    if (
        !errors.height &&
        !errors.min_height &&
        !errors.max_height &&
        (at(display.height) < at(display.min_height) ||
            at(display.height) > at(display.max_height))
    ) {
        errors.height = labels.outsideLimits;
    }

    return errors;
}

// A category's default icon: by its name, then the neutral one.
const CATEGORY_ICONS: Record<string, string> = {
    general: 'layout-grid',
    finance: 'wallet',
    sales: 'trending-up',
    operations: 'settings',
    people: 'users',
    marketing: 'megaphone',
    support: 'life-buoy',
};

export function categoryIcon(name: string | null | undefined): string {
    return CATEGORY_ICONS[(name ?? '').trim().toLowerCase()] ?? 'layout-grid';
}

// ---- API -----------------------------------------------------------------------------------------------------

// A request the server refused or that never arrived (status 0). `fields` are the 422's field errors, `current` the 409/423's state.
export class WizardError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly fields: Record<string, string[]> = {},
        readonly current: WizardDraft | null = null,
        // The error body's own members (`reason`, `check`, `setting`, `retry_after`), for a caller that reads them (the sample store, Story 3.8; the fetch, Story 3.9).
        readonly body: Record<string, unknown> = {},
    ) {
        super(`block wizard request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    error?: { code?: string; retry_after?: number };
    errors?: Record<string, string[]>;
    current?: { data?: WizardDraft };
    reason?: string;
    check?: unknown;
    setting?: string;
};

async function call(
    method: 'GET' | 'POST' | 'PUT',
    path: string,
    payload?: unknown,
    signal?: AbortSignal,
    background = false,
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
                // An autosave is not the Admin's own action: it must not extend the idle session (`IdleTimeout`).
                ...(background ? { 'X-Background': '1' } : {}),
                ...(method === 'GET'
                    ? {}
                    : {
                          'Content-Type': 'application/json',
                          'X-XSRF-TOKEN': xsrfToken(),
                      }),
            },
            ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new WizardError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new WizardError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.current?.data ?? null,
            {
                reason: json?.reason,
                check: json?.check,
                setting: json?.setting,
                retry_after: json?.error?.retry_after,
            },
        );
    }

    return json;
}

// The generic call of the wizard's API, for the Step 2 reads (`lib/blockSource.ts`).
export const wizardCall = call;

function asDraft(body: Body): WizardDraft {
    const draft = body.data as Partial<WizardDraft> | undefined;

    if (!draft || typeof draft.draft_id !== 'string') {
        throw new WizardError(500);
    }

    return {
        ...draft,
        config: draft.config ?? { schema_version: 1 },
    } as WizardDraft;
}

export async function fetchWizardDraft(
    id: string,
    signal?: AbortSignal,
): Promise<WizardDraft> {
    return asDraft(
        await call(
            'GET',
            `${DRAFT_BLOCKS_URL}/${encodeURIComponent(id)}`,
            undefined,
            signal,
        ),
    );
}

export async function createWizardDraft(
    name: string,
    type: string,
): Promise<WizardDraft> {
    return asDraft(await call('POST', DRAFT_BLOCKS_URL, { name, type }));
}

export type SaveBody = {
    name: string;
    type: string;
    expected_revision: number;
    lock_epoch: number;
    // The soft lock's per-tab token (Story 3.6); the server checks it against the lock itself.
    lock_token?: string;
    category_id?: string | null;
    step?: number;
    config?: DraftConfig;
    ui_state?: WizardUiState;
};

// `background`: an autosave or flush, sent with `X-Background: 1` so it does not extend the idle session. Save draft and Continue are the
// Admin's own actions and never set it.
export async function saveWizardDraft(
    id: string,
    body: SaveBody,
    options: { background?: boolean } = {},
): Promise<WizardDraft> {
    return asDraft(
        await call(
            'PUT',
            `${DRAFT_BLOCKS_URL}/${encodeURIComponent(id)}`,
            body,
            undefined,
            options.background === true,
        ),
    );
}

// One side of the revision-conflict chooser: the fields the Admin can tell apart at a glance.
export type Version = {
    name: string;
    description: string;
    width: number | null;
    height: string | null;
    savedAt: string | null;
};

export function versionOf(draft: WizardDraft): Version {
    return {
        name: draft.name,
        description: draft.config.description ?? '',
        width: draft.config.display?.width ?? null,
        height: draft.config.display?.height ?? null,
        savedAt: draft.updated_at,
    };
}

export const PUBLISHED_BLOCKS_URL = '/admin/blocks/published';

export async function fetchCategories(
    signal?: AbortSignal,
): Promise<BlockCategory[]> {
    const body = await call('GET', CATEGORIES_URL, undefined, signal);

    if (!Array.isArray(body.data)) {
        throw new WizardError(500);
    }

    return (body.data as BlockCategory[]).filter(
        (category) => !category.archived,
    );
}

// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    fetchMapping,
    flaggedFields,
    qualifierOf,
} from '../../resources/js/lib/blockMapping';
import type {
    RoleField,
    RolesReport,
} from '../../resources/js/lib/blockMapping';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import {
    blockMapLabels as labels,
    blockWizardLabels,
    draftLockLabels,
} from '../../resources/js/locales/labels';
import { emptySample } from '../../resources/js/lib/blockSample';
import RoleChip from '../../resources/js/components/wizard/RoleChip.vue';
import MapDataStep from '../../resources/js/components/wizard/MapDataStep.vue';

// Story 3.11b (UI stage 1): wizard step 3, Map Data: the shell, the status bar, the Roles section (scalar cards, roles table, role chips,
// suggestions, accepted summary), the record-path radiogroup, read-only, the recovery state and the small-screen banner.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    router: { visit: vi.fn(), on: () => () => {} },
}));

const { default: BlockWizard } =
    await import('../../resources/js/pages/admin/BlockWizard.vue');

const BASE = '/api/v1/admin/blocks/drafts/d-1';
const MONTH = '$.data.monthly[*].month';
const REVENUE = '$.data.monthly[*].revenue';
const RATE = '$.data.monthly[*].rate';
const CURRENCY = '$.data.currency';
const TOTAL = '$.summary.total';

function draft(overrides: Partial<WizardDraft> = {}): WizardDraft {
    return {
        draft_id: 'd-1',
        block_id: 'b-1',
        name: 'Revenue today',
        type: 'kpi-card',
        category: 'General',
        category_id: 'c-general',
        version: '1.0',
        state: 'draft',
        revision: 1,
        lock_epoch: 1,
        step: 3,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: { schema_version: 1, description: 'Daily revenue' },
        ...overrides,
    } as WizardDraft;
}

function field(over: Partial<RoleField> & { path: string }): RoleField {
    return {
        display: over.path.replace(/^\$\./, '').replace('[*]', '[]'),
        type: 'string',
        example: 'x',
        role: 'label',
        confidence: 'high',
        overridden: false,
        in_records: false,
        suggestion: null,
        ...over,
    };
}

function fields(flag = true): RoleField[] {
    return [
        field({ path: MONTH, role: 'time', in_records: true, example: 'Jan' }),
        field({
            path: REVENUE,
            type: 'integer | decimal',
            role: 'filter',
            confidence: 'low',
            in_records: true,
            example: '12345678901234567890.10',
            suggestion: flag ? { role: 'measure' } : null,
        }),
        field({
            path: RATE,
            type: 'decimal',
            role: 'measure',
            in_records: true,
            example: '1.0',
        }),
        field({ path: CURRENCY, role: 'label', example: 'USD' }),
        field({
            path: TOTAL,
            type: 'integer',
            role: 'value',
            example: '284680',
        }),
    ];
}

const CANDIDATE = {
    path: '$.data.monthly[*]',
    display: 'data.monthly[]',
    rows: 3,
    columns: ['month', 'revenue', 'rate'],
    depth: 2,
    suggested: true,
    pagination_like: false,
};

function roles(over: Partial<RolesReport> = {}): RolesReport {
    return {
        record_path: '$.data.monthly[*]',
        record_path_display: 'data.monthly[]',
        needs_choice: false,
        candidates: [CANDIDATE],
        fields: fields(),
        ...over,
    };
}

function mapping(
    revision: number,
    over: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        checklist: {
            groups: [
                {
                    group: 'KPI',
                    slots: [
                        {
                            slot: 'headline_value',
                            label: 'Headline',
                            group: 'KPI',
                            required: true,
                            state: 'auto_high',
                        },
                        {
                            slot: 'kpi_label',
                            label: 'KPI label',
                            group: 'KPI',
                            required: false,
                            state: 'auto_low',
                        },
                        {
                            slot: 'comparison',
                            label: 'Comparison',
                            group: 'KPI',
                            required: true,
                            state: 'required_missing',
                        },
                    ],
                },
            ],
            counts: {
                auto_mapped: 2,
                total: 3,
                confirmed: 1,
                needs_review: 1,
                required_missing: 1,
                type_mismatch: 0,
            },
            blockers: [],
            baseline: [],
            api_changed: [],
        },
        roles: roles(),
        slot_map: {},
        query_plan: null,
        draft_revision: revision,
        sample_revision: 1,
        plan_stale: false,
        sample: 'ok',
        ...over,
    };
}

function sampleRecord() {
    const node = (
        id: number,
        parent: number | null,
        depth: number,
        key: string | null,
        path: string,
        display: string,
        type: string,
        example: string | null,
        count: number | null = null,
    ) => ({ id, parent, depth, key, path, display, type, example, count });

    return {
        origin: 'fetched',
        text: '{}',
        size_bytes: 2,
        pasted_at: '2026-10-09T09:14:00Z',
        expires_at: '2026-10-09T10:14:00Z',
        contains_personal_data: false,
        valid: true,
        reason: null,
        line: null,
        column: null,
        problem: null,
        limit: null,
        setting: null,
        sample_revision: 1,
        request_key: null,
        fetch_status: 200,
        fetch_latency_ms: 12,
        shape: {
            records: {
                path: '$.data.monthly[*]',
                display: 'data.monthly[]',
                count: 7,
            },
            scalars: 5,
            arrays: 1,
            objects: 2,
            nodes: [
                node(0, null, 0, null, '$', '$', 'object', null),
                node(1, 0, 1, 'data', '$.data', 'data', 'object', null),
                node(
                    2,
                    1,
                    2,
                    'monthly',
                    '$.data.monthly',
                    'data.monthly',
                    'array',
                    null,
                    7,
                ),
            ],
            tables: {
                '2': {
                    columns: [
                        { key: 'month', display: 'data.monthly[].month' },
                        { key: 'revenue', display: 'data.monthly[].revenue' },
                        { key: 'rate', display: 'data.monthly[].rate' },
                    ],
                    rows: [1, 2, 3, 4, 5, 6, 7].map((n) => [
                        `m${n}`,
                        `${n}00.50`,
                        '1.0',
                    ]),
                    total: 7,
                },
            },
        },
    };
}

type Reply = { status: number; json?: unknown; etag?: string };
type Call = {
    url: string;
    method: string;
    body: Record<string, any> | null;
    headers: Record<string, string>;
};
const calls: Call[] = [];
let revision = 1;
let lockReply: () => Reply = () => ({
    status: 200,
    json: { data: { enabled: false } },
});
let mappingReply: () => Reply;
let mappingGate: Promise<void> | null = null;
let putReply: (call: Call) => Reply;
let wrapper: VueWrapper | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const chip = (path: string) =>
    $$('[data-test=role-chip]').find((c) => c.dataset.fieldPath === path)!;
const puts = (tail: string) =>
    calls.filter(
        (c) => c.method === 'PUT' && c.url === `${BASE}/mapping/${tail}`,
    );
const reads = () =>
    calls.filter((c) => c.method === 'GET' && c.url === `${BASE}/mapping`);
const polite = () =>
    document.querySelector('[data-announcer=polite]')?.textContent ?? '';

async function settle(): Promise<void> {
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

async function mountPage(search = '?draft=d-1&step=3') {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await settle();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await settle();
}

async function pick(path: string, role: string): Promise<void> {
    await click(chip(path));
    await click($(`[data-role-option=${role}]`));
}

// A server that applies a role write to what it serves next.
function serve(initial: Record<string, unknown> = mapping(1)): void {
    let current = initial;
    const base = new Map(
        ((initial.roles ?? roles()) as RolesReport).fields.map((f) => [
            f.path,
            f,
        ]),
    );

    mappingReply = () => ({
        status: 200,
        json: { data: { ...current, draft_revision: revision } },
        etag: `"d${revision}-s1"`,
    });
    putReply = (call) => {
        revision += 1;

        const report = (current.roles ?? roles()) as RolesReport;

        if (call.url.endsWith('/field-roles')) {
            current = {
                ...current,
                roles: {
                    ...report,
                    fields: report.fields.map((f) =>
                        f.path === call.body?.path
                            ? call.body?.role === null
                                ? base.get(f.path)!
                                : {
                                      ...f,
                                      role: call.body?.role,
                                      overridden: true,
                                      suggestion: null,
                                  }
                            : f,
                    ),
                },
            };
        } else {
            current = {
                ...current,
                roles: { ...report, record_path: call.body?.record_path },
            };
        }

        const checklist = (current.checklist ?? {}) as Record<string, unknown>;

        return {
            status: 200,
            json: {
                data: {
                    ...checklist,
                    draft_revision: revision,
                    sample_revision: 1,
                    query_plan: null,
                },
            },
        };
    };
}

beforeEach(() => {
    vi.useRealTimers();
    setActivePinia(createPinia());
    page.props = {
        autosave: { debounce_ms: null },
        shell: {
            area: 'admin',
            items: [],
            can: { 'blocks.edit': true, 'blocks.publish': true },
        },
    };
    setConnectionForTest(true);
    calls.length = 0;
    revision = 1;
    mappingGate = null;
    sessionStorage.clear();
    localStorage.clear();
    Object.defineProperty(window, 'innerWidth', {
        value: 1280,
        configurable: true,
        writable: true,
    });
    lockReply = () => ({
        status: 200,
        json: { data: { enabled: false } },
    });
    serve();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (
                url: string,
                init?: {
                    method?: string;
                    body?: string;
                    headers?: Record<string, string>;
                },
            ) => {
                const method = init?.method ?? 'GET';
                let reply: Reply;

                if (/\/lock(\/|$)/.test(url)) {
                    reply = lockReply();
                } else {
                    calls.push({
                        url,
                        method,
                        body: init?.body ? JSON.parse(init.body) : null,
                        headers: init?.headers ?? {},
                    });

                    if (url === '/api/v1/admin/blocks/categories') {
                        reply = {
                            status: 200,
                            json: {
                                data: [
                                    {
                                        id: 'c-general',
                                        name: 'General',
                                        archived: false,
                                    },
                                ],
                            },
                        };
                    } else if (url === `${BASE}/mapping`) {
                        reply = mappingReply();

                        if (mappingGate) {
                            const gate = mappingGate;

                            mappingGate = null;
                            await gate;
                        }
                    } else if (url.startsWith(`${BASE}/mapping/`)) {
                        reply = putReply(calls[calls.length - 1]);
                    } else if (url === `${BASE}/sample`) {
                        reply = {
                            status: 200,
                            json: { data: sampleRecord() },
                        };
                    } else if (url === BASE && method === 'GET') {
                        reply = { status: 200, json: { data: draft() } };
                    } else if (url === BASE && method === 'PUT') {
                        reply = {
                            status: 200,
                            json: {
                                data: draft({
                                    revision: 9,
                                    config: JSON.parse(init!.body!).config,
                                }),
                            },
                        };
                    } else {
                        reply = { status: 200, json: { data: [] } };
                    }
                }

                return {
                    ok: reply.status < 400,
                    status: reply.status,
                    headers: {
                        get: (name: string) =>
                            name === 'ETag' ? (reply.etag ?? null) : null,
                    },
                    json: async () => reply.json ?? {},
                };
            },
        ),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Map Data: the step opens', () => {
    it('reads the mapping once and shows the summaries, the status bar and the three sections', async () => {
        await mountPage();

        expect(reads()).toHaveLength(1);
        expect(reads()[0].headers['X-Background']).toBeUndefined();
        expect($('[data-test=map-data-step]')).not.toBeNull();
        expect($$('[data-test=map-summary]')).toHaveLength(2);
        expect(text('[data-test=step-summaries]')).toContain(
            'Step 1 · Configure Block',
        );
        expect(text('[data-test=status-summary]')).toBe(
            'Auto-mapped 2 of 3 · 1 confirmed · 1 required missing',
        );
        expect($('[data-test=status-meter]')?.getAttribute('aria-hidden')).toBe(
            'true',
        );
        expect($$('[data-test=status-meter] span')).toHaveLength(3);
        expect(text('[data-slot=section-card]')).toContain(labels.rolesTitle);
        expect(text('[data-test=slots-placeholder]')).toBe(
            labels.slotsPlaceholder,
        );
        expect(
            $('[data-test=transforms-placeholder]')?.hasAttribute('open'),
        ).toBe(false);
        // The sticky live preview stays on the right.
        expect($('[data-test=preview-column]')).not.toBeNull();
        expect($('[data-test=later-step]')).toBeNull();
    });

    it('shows a loading state, then a failure with Try again', async () => {
        let release: () => void = () => {};
        const gate = new Promise<void>((resolve) => (release = resolve));
        const original = mappingReply;

        mappingReply = () => ({ status: 500, json: {} });
        await mountPage();
        expect(text('[data-test=map-failed]')).toContain(labels.loadFailed);

        mappingReply = original;
        await click($('[data-test=map-retry]'));
        expect($('[data-test=map-failed]')).toBeNull();
        expect($('[data-test=status-bar]')).not.toBeNull();
        release();
        await gate;
    });

    it('lays out one scalar card per single value with its key, type, example and role chip with qualifier', async () => {
        await mountPage();

        const cards = $$('[data-test=scalar-card]');

        expect(cards.map((c) => c.dataset.path)).toEqual([CURRENCY, TOTAL]);
        expect(
            cards[1].querySelector('[data-test=scalar-key]')?.textContent,
        ).toBe('summary.total');
        expect(
            cards[1].querySelector('[data-test=scalar-type]')?.textContent,
        ).toBe('integer');
        expect(
            cards[1].querySelector('[data-test=scalar-example]')?.textContent,
        ).toBe('284680');
        expect(
            cards[1].querySelector('[data-test=scalar-qualifier]')?.textContent,
        ).toBe('· main');
        expect(
            cards[0].querySelector('[data-test=scalar-qualifier]')?.textContent,
        ).toBe('· unit');
        expect(
            cards[1]
                .querySelector('[data-test=role-chip]')
                ?.getAttribute('aria-label'),
        ).toBe('Role for summary.total: Value');
        expect($('[data-test=scalar-cards]')?.className).toContain(
            'grid-cols-1',
        );
        expect($('[data-test=scalar-cards]')?.className).toContain(
            'xl:grid-cols-4',
        );
    });

    it('renders a real roles table of at most five sample rows with a role chip above each column', async () => {
        await mountPage();

        const region = $('[data-test=roles-table-region]')!;

        expect(region.getAttribute('role')).toBe('region');
        expect(region.getAttribute('tabindex')).toBe('0');
        expect(region.getAttribute('aria-label')).toBe(
            'Sample rows, scrolls sideways',
        );
        expect($$('[data-test=roles-table] th[scope=col]')).toHaveLength(3);
        expect($$('[data-test=roles-row]')).toHaveLength(5);
        expect(
            $$('[data-test=roles-table] th [data-test=role-chip]').map((c) =>
                c.getAttribute('aria-label'),
            ),
        ).toEqual([
            'Role for data.monthly[].month: Time',
            'Role for data.monthly[].revenue: Filter/Category, possible wrong role',
            'Role for data.monthly[].rate: Measure',
        ]);
        // Every chip prints its role's name: never a colour alone.
        expect(chip(RATE).textContent).toContain('Measure');
    });
});

describe('Map Data: role chips', () => {
    it('is a menu button with six menuitemradio items, each with a description, and marks the current role', async () => {
        await mountPage();
        await click(chip(RATE));

        expect(chip(RATE).getAttribute('aria-haspopup')).toBe('menu');
        expect(chip(RATE).getAttribute('aria-expanded')).toBe('true');

        const items = $$('[role=menuitemradio]');

        expect(items).toHaveLength(6);
        expect(items.map((i) => i.dataset.roleOption)).toEqual([
            'label',
            'value',
            'dimension',
            'measure',
            'time',
            'filter',
        ]);
        expect(
            $('[data-role-option=measure]')?.getAttribute('aria-checked'),
        ).toBe('true');

        const described = items[0].getAttribute('aria-describedby')!;

        expect(document.getElementById(described)?.textContent).toBe(
            labels.roleDescriptions.label,
        );
        // The focus starts on the current role.
        expect(document.activeElement).toBe($('[data-role-option=measure]'));
    });

    it('moves with the arrow keys, Home and End, and Escape closes and returns focus to the chip', async () => {
        await mountPage();
        chip(RATE).dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        await settle();
        expect(document.activeElement).toBe($('[data-role-option=label]'));

        const key = (name: string) =>
            document.activeElement!.dispatchEvent(
                new KeyboardEvent('keydown', { key: name, bubbles: true }),
            );

        key('ArrowDown');
        expect(document.activeElement).toBe($('[data-role-option=value]'));
        key('End');
        expect(document.activeElement).toBe($('[data-role-option=filter]'));
        key('Home');
        expect(document.activeElement).toBe($('[data-role-option=label]'));
        key('Escape');
        await settle();
        expect($('[role=menu]')).toBeNull();
        expect(document.activeElement).toBe(chip(RATE));
    });

    it('changes a role: PUT with the revision and claim, the chip updates, it is announced, focus returns and Undo shows', async () => {
        await mountPage();
        await pick(RATE, 'dimension');

        expect(puts('field-roles')).toHaveLength(1);
        expect(puts('field-roles')[0].body).toEqual({
            path: RATE,
            role: 'dimension',
            draft_revision: 1,
            lock_epoch: 1,
        });
        expect(chip(RATE).getAttribute('aria-label')).toBe(
            'Role for data.monthly[].rate: Dimension',
        );
        expect(polite()).toContain(
            'Role for data.monthly[].rate is now Dimension.',
        );
        expect(document.activeElement).toBe(chip(RATE));
        expect(text('[data-test=role-undo]')).toContain(
            'data.monthly[].rate is now Dimension.',
        );
        // The mapping is read again, in the background, after the write.
        expect(reads()).toHaveLength(2);
        expect(reads()[1].headers['X-Background']).toBe('1');
    });

    it('adopts the returned revision, so Undo and the next save are decided against it', async () => {
        await mountPage();
        await pick(RATE, 'dimension');
        await click($('[data-test=undo-role]'));

        expect(puts('field-roles')).toHaveLength(2);
        // The role was only inferred, so Undo clears the override instead of storing it again.
        expect(puts('field-roles')[1].body).toMatchObject({
            path: RATE,
            role: null,
            draft_revision: 2,
        });
        expect(chip(RATE).getAttribute('aria-label')).toBe(
            'Role for data.monthly[].rate: Measure',
        );
        expect($('[data-test=role-undo]')).toBeNull();
        expect(polite()).toContain('Undone.');

        // A Draft save carries the revision the mapping writes reached (3), not the one it loaded.
        await click($('[data-test=save-draft]'));

        const save = calls.find((c) => c.method === 'PUT' && c.url === BASE);

        expect(save?.body?.expected_revision).toBe(3);
        // ...and never sends the server-owned mapping keys.
        expect(Object.keys(save?.body?.config ?? {})).not.toContain(
            'field_roles',
        );
    });

    it('does not write when the same role is chosen again', async () => {
        await mountPage();
        await pick(RATE, 'measure');

        expect(puts('field-roles')).toHaveLength(0);
        expect(document.activeElement).toBe(chip(RATE));
    });
});

describe('Map Data: suspect roles', () => {
    it('shows a suggestion row and flags the chip; Set as Measure applies it and moves focus to the chip', async () => {
        await mountPage();

        expect(text('[data-test=suggestion-row]')).toContain(
            '✦ data.monthly[].revenue looks like a Measure — use as series?',
        );
        expect(chip(REVENUE).dataset.flagged).toBe('true');
        expect(
            chip(REVENUE)
                .querySelector('[data-test=role-flag]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');

        await click($('[data-test=suggestion-accept]'));

        expect(puts('field-roles')[0].body).toMatchObject({
            path: REVENUE,
            role: 'measure',
        });
        expect($('[data-test=suggestion-row]')).toBeNull();
        expect(chip(REVENUE).getAttribute('aria-label')).toBe(
            'Role for data.monthly[].revenue: Measure',
        );
        expect(document.activeElement).toBe(chip(REVENUE));
    });

    it('Keep as dismisses the suggestion in memory and writes nothing', async () => {
        await mountPage();
        expect($('[data-test=suggestion-keep]')?.textContent).toContain(
            'Keep as Filter/Category',
        );
        await click($('[data-test=suggestion-keep]'));

        expect(puts('field-roles')).toHaveLength(0);
        expect($('[data-test=suggestion-row]')).toBeNull();
        expect(chip(REVENUE)?.dataset.flagged).toBeUndefined();
    });
});

describe('Map Data: the record path', () => {
    function several(): Record<string, unknown> {
        return mapping(1, {
            roles: roles({
                needs_choice: true,
                candidates: [
                    CANDIDATE,
                    {
                        path: '$.meta.links[*]',
                        display: 'meta.links[]',
                        rows: 3,
                        columns: ['url'],
                        depth: 3,
                        suggested: false,
                        pagination_like: true,
                    },
                ],
            }),
        });
    }

    it('shows a radiogroup of candidates with rows and columns, Suggested and the pagination note', async () => {
        serve(several());
        await mountPage();

        const group = $('[data-test=record-path-group]')!;

        expect(group.getAttribute('role')).toBe('radiogroup');
        expect(text('[data-test=record-path]')).toContain(labels.recordPath);

        const options = $$('[data-test=record-path-option]');

        expect(options).toHaveLength(2);
        expect(options[0].textContent).toContain('3 rows · 3 columns');
        expect(options[0].textContent).toContain('Suggested');
        expect(options[1].textContent).toContain('3 rows · 1 column');
        expect(options[1].textContent).toContain(
            'looks like pagination, not data',
        );
        expect(
            $$('[data-test=record-path-group] [role=radio]').map((r) =>
                r.getAttribute('aria-checked'),
            ),
        ).toEqual(['true', 'false']);
    });

    it('persists the choice with PUT record-path', async () => {
        serve(several());
        await mountPage();
        await click($$('[data-test=record-path-group] [role=radio]')[1]);

        expect(puts('record-path')).toHaveLength(1);
        expect(puts('record-path')[0].body).toEqual({
            record_path: '$.meta.links[*]',
            draft_revision: 1,
            lock_epoch: 1,
        });
        expect(polite()).toContain('Rows now come from');
        expect(
            $$('[data-test=record-path-group] [role=radio]').map((r) =>
                r.getAttribute('aria-checked'),
            ),
        ).toEqual(['false', 'true']);
    });

    it('is not shown when there is one array', async () => {
        await mountPage();

        expect($('[data-test=record-path]')).toBeNull();
    });
});

describe('Map Data: roles accepted', () => {
    const clean = () => mapping(1, { roles: roles({ fields: fields(false) }) });

    it('opens collapsed to the summary line when no role is flagged, and Edit roles re-expands it', async () => {
        serve(clean());
        await mountPage();

        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect($('[data-test=roles-table]')).toBeNull();
        expect(text('[data-test=roles-summary-line]')).toContain(
            labels.summaryTitle,
        );
        expect(
            $$('[data-test=summary-field]').map((li) => li.textContent),
        ).toContain('data.monthly[].rateMeasure');

        await click($('[data-test=edit-roles]'));
        expect($('[data-test=roles-summary-line]')).toBeNull();
        expect($('[data-test=roles-table]')).not.toBeNull();
        expect(document.activeElement?.getAttribute('data-test')).toBe(
            'role-chip',
        );
    });

    it('Looks right collapses, announces only because focus was inside, and focuses Edit roles', async () => {
        await mountPage();
        expect($('[data-test=roles-table]')).not.toBeNull();

        // Focus is in the roles table (a chip), which is what the announcement depends on.
        chip(RATE).focus();
        await click($('[data-test=looks-right]'));

        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect(polite()).toContain('Roles accepted. Roles table collapsed.');
        expect(document.activeElement).toBe($('[data-test=edit-roles]'));
        expect(puts('field-roles')).toHaveLength(0);
    });

    it('does not announce when only the Looks right button had focus', async () => {
        await mountPage();
        ($('[data-test=looks-right]') as HTMLElement).focus();
        await click($('[data-test=looks-right]'));

        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect(polite()).not.toContain('Roles accepted');
    });

    it('does not announce when focus was not in the roles section', async () => {
        await mountPage();
        (document.activeElement as HTMLElement | null)?.blur();
        await click($('[data-test=looks-right]'));

        // `click()` does not move focus, so nothing inside the section had it.
        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect(polite()).not.toContain('Roles accepted');
    });

    it('collapses at once when focus is elsewhere, and Edit roles keeps it open while editing', async () => {
        await mountPage();
        await click($('[data-test=suggestion-keep]'));
        // Focus is on the document body: collapsing is immediate.
        expect($('[data-test=roles-summary-line]')).not.toBeNull();

        await click($('[data-test=edit-roles]'));
        // Roles are still open although nothing is flagged: editing keeps them open.
        expect($('[data-test=roles-table]')).not.toBeNull();
    });

    it('collapses after the last flag is resolved, but only once focus leaves the section', async () => {
        await mountPage();
        await click(chip(REVENUE));
        await click($('[data-role-option=measure]'));

        // The chip has focus (returned after the change): the table stays open.
        expect(document.activeElement).toBe(chip(REVENUE));
        expect($('[data-test=roles-table]')).not.toBeNull();

        chip(REVENUE).dispatchEvent(
            new FocusEvent('focusout', { bubbles: true, relatedTarget: null }),
        );
        await settle();
        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect(polite()).not.toContain('Roles accepted');
    });

    it('re-expands when a role is flagged later, without moving focus', async () => {
        serve(clean());
        await mountPage();
        expect($('[data-test=roles-summary-line]')).not.toBeNull();

        const outside = document.createElement('button');

        document.body.appendChild(outside);
        outside.focus();

        // The server now sees a suspect role (the reread after a record-path or role change).
        mappingReply = () => ({
            status: 200,
            json: { data: mapping(2) },
            etag: '"d2-s1"',
        });
        revision = 1;
        // Any write triggers the background reread.
        await click($('[data-test=edit-roles]'));
        await pick(RATE, 'dimension');

        expect($('[data-test=suggestion-row]')).not.toBeNull();
        expect($('[data-test=roles-table]')).not.toBeNull();
        outside.remove();
    });
});

describe('Map Data: the status bar announces only the figures that matter', () => {
    it('speaks when needs-review or required-missing changes and stays quiet otherwise', async () => {
        await mountPage();
        expect(polite()).toBe('');

        // A role edit that leaves both figures alone: silent about the status.
        await pick(RATE, 'dimension');
        expect(polite()).not.toContain('Auto-mapped');

        mappingReply = () => ({
            status: 200,
            json: {
                data: {
                    ...mapping(3),
                    checklist: {
                        ...(mapping(3).checklist as object),
                        counts: {
                            auto_mapped: 3,
                            total: 3,
                            confirmed: 2,
                            needs_review: 0,
                            required_missing: 0,
                            type_mismatch: 0,
                        },
                    },
                },
            },
            etag: '"d3-s1"',
        });
        await pick(CURRENCY, 'label');
        await pick(TOTAL, 'measure');
        await new Promise((resolve) => setTimeout(resolve, 650));
        expect(polite()).toContain(
            'Auto-mapped 3 of 3 · 2 confirmed · 0 required missing',
        );
    });
});

describe('Map Data: read-only and refusals', () => {
    it('disables the chips, suggestions and radios with the reason, and writes nothing', async () => {
        lockReply = () => ({
            status: 200,
            json: {
                data: {
                    enabled: true,
                    status: 'held',
                    holder: { name: 'Ada', since: '2026-10-09T09:00:00Z' },
                },
            },
        });
        serve(
            mapping(1, {
                roles: roles({
                    needs_choice: true,
                    candidates: [
                        CANDIDATE,
                        { ...CANDIDATE, path: '$.other[*]', suggested: false },
                    ],
                }),
            }),
        );
        await mountPage();

        // Everything is visible.
        expect($$('[data-test=scalar-card]')).toHaveLength(2);
        expect($$('[data-test=roles-row]')).toHaveLength(5);
        expect($('[data-test=roles-blocked]')?.textContent?.trim()).toBe(
            draftLockLabels.readOnly,
        );
        expect(
            $$('[data-test=role-chip]').every((c) =>
                c.hasAttribute('disabled'),
            ),
        ).toBe(true);
        expect(
            $('[data-test=suggestion-accept]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(
            $$('[data-test=record-path-group] [role=radio]').every((r) =>
                r.hasAttribute('disabled'),
            ),
        ).toBe(true);

        await click($('[data-test=suggestion-accept]'));
        await click($$('[data-test=record-path-group] [role=radio]')[1]);
        expect(puts('field-roles')).toHaveLength(0);
        expect(puts('record-path')).toHaveLength(0);
    });

    it('opens the revision chooser on 409 revision_conflict and changes nothing locally', async () => {
        await mountPage();
        putReply = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.revision_conflict' },
                current: { data: draft({ revision: 5, name: 'Theirs' }) },
            },
        });
        await pick(RATE, 'dimension');

        expect($('[data-test=conflict-chooser]')).not.toBeNull();
        expect(chip(RATE).getAttribute('aria-label')).toBe(
            'Role for data.monthly[].rate: Measure',
        );
        expect($('[data-test=role-undo]')).toBeNull();
    });

    it('shows the lost-lock notice on 423 and makes the step read-only', async () => {
        await mountPage();
        putReply = () => ({
            status: 423,
            json: { error: { code: 'edit_lock.lost' } },
        });
        await pick(RATE, 'dimension');

        expect($('[data-test=roles-blocked]')).not.toBeNull();
        expect(
            $$('[data-test=role-chip]').every((c) =>
                c.hasAttribute('disabled'),
            ),
        ).toBe(true);
    });
});

describe('Map Data: no usable sample', () => {
    it.each([
        ['expired', labels.sampleExpired],
        ['invalid', labels.sampleInvalid],
    ])(
        'says so and points back to step 2 when the sample is %s',
        async (sample, message) => {
            serve(mapping(1, { sample, checklist: null, roles: null }));
            await mountPage();

            expect(text('[data-test=sample-gone]')).toContain(message);
            expect($('[data-test=status-bar]')).toBeNull();
            expect($('[data-test=roles]')).toBeNull();

            await click($('[data-test=to-source]'));
            expect($('[aria-current=step]')?.getAttribute('data-step')).toBe(
                '2',
            );
        },
    );

    it('shows an empty state when the sample has no fields', async () => {
        serve(
            mapping(1, {
                roles: roles({
                    candidates: [],
                    record_path: null,
                    record_path_display: null,
                    fields: [],
                }),
            }),
        );
        await mountPage();
        await click($('[data-test=edit-roles]'));

        expect(text('[data-test=no-fields]')).toBe(labels.noFields);
    });
});

describe('Map Data: small screens', () => {
    beforeEach(() => {
        window.innerWidth = 500;
    });

    it('shows msg:map-small-screen once and never after Dismiss', async () => {
        await mountPage();

        expect(text('[data-test=small-screen]')).toContain(
            en['map-small-screen'],
        );
        await click($('[data-slot=banner-dismiss]'));
        expect($('[data-test=small-screen]')).toBeNull();
        expect(
            localStorage.getItem('dashflow.map-small-screen.dismissed'),
        ).toBe('1');

        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        await mountPage();
        expect($('[data-test=small-screen]')).toBeNull();
    });

    it('survives a browser that keeps nothing', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        await mountPage();
        expect($('[data-test=small-screen]')).not.toBeNull();
        await click($('[data-slot=banner-dismiss]'));
        expect($('[data-test=small-screen]')).toBeNull();
        expect($('[data-test=status-bar]')).not.toBeNull();
    });

    it('does not show it on a wide screen', async () => {
        window.innerWidth = 1280;
        await mountPage();

        expect($('[data-test=small-screen]')).toBeNull();
    });
});

describe('the mapping client', () => {
    it('sends If-None-Match and answers null data on a 304', async () => {
        mappingReply = () => ({ status: 304 });

        const result = await fetchMapping('d-1', {
            etag: '"d1-s1"',
            background: true,
        });

        expect(result).toEqual({ data: null, etag: '"d1-s1"' });
        expect(calls[0].headers['If-None-Match']).toBe('"d1-s1"');
        expect(calls[0].headers['X-Background']).toBe('1');
    });

    it('flags only undismissed suggestions and derives qualifiers from the keys', () => {
        const all = fields();

        expect(flaggedFields(all, new Set()).map((f) => f.path)).toEqual([
            REVENUE,
        ]);
        expect(flaggedFields(all, new Set([REVENUE]))).toEqual([]);

        const scalars = [
            field({ path: '$.previous_total', role: 'value' }),
            field({ path: '$.total', role: 'value' }),
            field({ path: '$.as_of', role: 'time' }),
        ];

        expect(qualifierOf(scalars[0], scalars)).toBe('baseline');
        expect(qualifierOf(scalars[1], scalars)).toBe('main');
        expect(qualifierOf(scalars[2], scalars)).toBe('asOf');
    });
});

describe('Map Data: review fixes', () => {
    const waitMs = (ms: number) =>
        new Promise((resolve) => setTimeout(resolve, ms));

    it('undoing an auto-detected role leaves no override', async () => {
        await mountPage();
        await pick(RATE, 'dimension');
        await click($('[data-test=undo-role]'));

        expect(puts('field-roles')[1].body?.role).toBeNull();
        expect(chip(RATE).getAttribute('aria-label')).toContain('Measure');
        // The server reads it as inferred again.
        const last = reads()[reads().length - 1];

        expect(last).toBeDefined();
    });

    it('undoing an overridden role stores the old role again', async () => {
        const start = mapping(1);

        (start.roles as RolesReport).fields = fields().map((f) =>
            f.path === RATE ? { ...f, overridden: true } : f,
        );
        serve(start);
        await mountPage();
        await pick(RATE, 'dimension');
        await click($('[data-test=undo-role]'));

        expect(puts('field-roles')[1].body?.role).toBe('measure');
    });

    it('says a change was not saved when the write cannot run or fails, visibly and announced', async () => {
        await mountPage();
        putReply = () => ({ status: 500, json: {} });
        await pick(RATE, 'dimension');
        expect(text('[data-test=map-not-saved]')).toBe(labels.notSaved);
        expect(
            document.querySelector('[data-announcer=assertive]')?.textContent,
        ).toBe(labels.notSaved);
        // Nothing changed on screen.
        expect(chip(RATE).getAttribute('aria-label')).toContain('Measure');

        wrapper?.unmount();
        document.body.innerHTML = '';
        resetAnnouncer();

        const sample = emptySample();

        sample.stored = sampleRecord() as never;
        wrapper = mount(MapDataStep as never, {
            attachTo: document.body,
            props: {
                draftId: 'd-1',
                sample,
                revision: () => 1,
                claim: () => null,
                run: (task: () => Promise<unknown>) => task(),
                summaries: [],
            },
            global: { plugins: [createCatalogue()] },
        });
        await settle();
        const before = puts('field-roles').length;

        await pick(RATE, 'dimension');
        expect(text('[data-test=map-not-saved]')).toBe(labels.notSaved);
        expect(puts('field-roles')).toHaveLength(before);
    });

    it('reads when the Draft arrives after the step opened', async () => {
        const sample = emptySample();

        wrapper = mount(MapDataStep as never, {
            attachTo: document.body,
            props: {
                draftId: null,
                sample,
                revision: () => 1,
                claim: () => ({ lock_epoch: 1 }),
                run: (task: () => Promise<unknown>) => task(),
                summaries: [],
            },
            global: { plugins: [createCatalogue()] },
        });
        await settle();
        expect($('[data-test=map-failed]')).not.toBeNull();
        await wrapper.setProps({ draftId: 'd-1' });
        await settle();
        expect($('[data-test=status-bar]')).not.toBeNull();
    });

    it('shows a failure with Try again when the sample is fine but there are no roles, and hides an empty status bar', async () => {
        serve(mapping(1, { roles: null }));
        await mountPage();
        expect($('[data-test=map-failed]')).not.toBeNull();
        expect($('[data-test=map-retry]')).not.toBeNull();

        serve(mapping(1, { checklist: null }));
        await click($('[data-test=map-retry]'));
        expect($('[data-test=status-bar]')).toBeNull();
        expect($('[data-test=roles]')).not.toBeNull();
    });

    it('keeps the record path visible when nothing is flagged, and open while the choice is pending', async () => {
        serve(
            mapping(1, {
                roles: roles({
                    needs_choice: true,
                    fields: fields(false),
                    candidates: [
                        CANDIDATE,
                        { ...CANDIDATE, path: '$.other[*]', suggested: false },
                    ],
                }),
            }),
        );
        await mountPage();

        expect($('[data-test=record-path]')).not.toBeNull();
        // The choice is pending, so the roles are not collapsed even though nothing is flagged.
        expect($('[data-test=roles-summary-line]')).toBeNull();

        await click($('[data-test=looks-right]'));
        expect($('[data-test=roles-summary-line]')).not.toBeNull();
        expect($('[data-test=record-path]')).not.toBeNull();
    });

    it('announces the role change and then the status, so neither overwrites the other', async () => {
        await mountPage();
        mappingReply = () => ({
            status: 200,
            json: {
                data: {
                    ...mapping(2),
                    checklist: {
                        ...(mapping(2).checklist as object),
                        counts: {
                            auto_mapped: 3,
                            total: 3,
                            confirmed: 3,
                            needs_review: 0,
                            required_missing: 0,
                            type_mismatch: 0,
                        },
                    },
                },
            },
            etag: '"d2-s1"',
        });
        await pick(RATE, 'dimension');
        expect(polite()).toContain('Role for data.monthly[].rate is now');
        expect(polite()).not.toContain('Auto-mapped');

        await waitMs(650);
        expect(polite()).toContain('Auto-mapped 3 of 3');
    });

    it('drops a read that started before a newer write finished', async () => {
        await mountPage();

        let release: () => void = () => {};

        mappingGate = new Promise<void>((resolve) => (release = resolve));
        await pick(RATE, 'dimension');
        // The read after that change is still on its way; another change goes through meanwhile.
        await pick(CURRENCY, 'value');
        release();
        await settle();

        expect(chip(CURRENCY).getAttribute('aria-label')).toBe(
            'Role for data.currency: Value',
        );
        expect(chip(RATE).getAttribute('aria-label')).toContain('Dimension');
    });

    it('gives a field in the records that has no column a card of its own', async () => {
        const start = mapping(1);
        const extra = '$.data.monthly[*].extra';

        (start.roles as RolesReport).fields.push(
            field({ path: extra, role: 'dimension', in_records: true }),
        );
        serve(start);
        await mountPage();

        const card = $$('[data-test=scalar-card]').find(
            (c) => c.dataset.path === extra,
        );

        expect(card).toBeDefined();
        expect(chip(extra)).toBeDefined();
    });

    it('blocks Looks right with the reason when the step is read-only', async () => {
        lockReply = () => ({
            status: 200,
            json: {
                data: {
                    enabled: true,
                    status: 'held',
                    holder: { name: 'Ada', since: '2026-10-09T09:00:00Z' },
                },
            },
        });
        await mountPage();

        expect(
            $('[data-test=looks-right]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        await click($('[data-test=looks-right]'));
        expect($('[data-test=roles-summary-line]')).toBeNull();
    });

    it('keeps the old data and shows refresh-failed when the re-read after a write fails with 500', async () => {
        await mountPage();
        mappingReply = () => ({ status: 500, json: {} });
        await pick(RATE, 'dimension');

        expect($('[data-test=refresh-failed]')).not.toBeNull();
        expect(chip(RATE).getAttribute('aria-label')).toContain('Dimension');
        expect($('[data-test=map-failed]')).toBeNull();
    });

    it('takes the sign-in path when the re-read after a write fails with 401', async () => {
        const assign = vi.fn();

        vi.spyOn(window.location, 'assign').mockImplementation(assign);
        await mountPage();
        mappingReply = () => ({ status: 401, json: {} });
        await pick(RATE, 'dimension');

        expect(assign).toHaveBeenCalledWith('/login');
    });

    it('is blocked with the offline reason while offline', async () => {
        await mountPage();
        setConnectionForTest(false);
        await settle();

        expect(text('[data-test=map-blocked]')).toBe(en['offline-editing']);
        expect(
            $$('[data-test=role-chip]').every((c) =>
                c.hasAttribute('disabled'),
            ),
        ).toBe(true);
        setConnectionForTest(true);
    });

    it('is blocked with the chooser reason while a revision conflict is open', async () => {
        await mountPage();
        putReply = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.revision_conflict' },
                current: { data: draft({ revision: 5 }) },
            },
        });
        await pick(RATE, 'dimension');

        expect(text('[data-test=map-blocked]')).toBe(
            blockWizardLabels.conflictTitle,
        );
    });
});

describe('RoleChip', () => {
    function chipWrapper(props: Record<string, unknown> = {}) {
        return mount(RoleChip as never, {
            attachTo: document.body,
            props: {
                field: 'rate',
                path: '$.rate',
                role: 'measure',
                ...props,
            },
        });
    }

    it('closes its menu when it becomes disabled, and a choice made then does nothing', async () => {
        wrapper = chipWrapper();
        await click($('[data-test=role-chip]'));

        const item = $('[data-role-option=dimension]')!;

        expect($('[role=menu]')).not.toBeNull();
        await wrapper.setProps({ disabled: true });
        expect($('[role=menu]')).toBeNull();
        item.click();
        await settle();
        expect(wrapper.emitted('change')).toBeUndefined();
    });

    it('moves to the last item on ArrowUp and the first on ArrowDown when focus is not on an item', async () => {
        wrapper = chipWrapper();
        await click($('[data-test=role-chip]'));
        (document.activeElement as HTMLElement).blur();

        const menu = $('[role=menu]')!;

        menu.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }),
        );
        expect(document.activeElement).toBe($('[data-role-option=filter]'));
        (document.activeElement as HTMLElement).blur();
        menu.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        expect(document.activeElement).toBe($('[data-role-option=label]'));
    });

    it('shows a role it does not know by its raw name', async () => {
        wrapper = chipWrapper({ role: 'mystery' });

        expect($('[data-test=role-chip]')?.getAttribute('aria-label')).toBe(
            'Role for rate: mystery',
        );
        expect($('[data-test=role-chip]')?.textContent).toContain('mystery');
    });
});

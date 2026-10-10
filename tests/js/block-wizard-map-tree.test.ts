// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { listingOf } from '../../resources/js/lib/fieldTree';
import type {
    ApiChange,
    Blocker,
    ChecklistSlot,
    PickerField,
} from '../../resources/js/lib/blockMapping';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { blockMapLabels as labels } from '../../resources/js/locales/labels';

// Story 3.11d (UI): the searchable field tree and the Datasets suggestion in the record-path list of wizard step 3.
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

function sampleRecord() {
    const node = (
        id: number,
        parent: number | null,
        key: string | null,
        path: string,
        display: string,
        type: string,
        example: string | null,
        count: number | null = null,
    ) => ({
        id,
        parent,
        depth: path === '$' ? 0 : path.split(/\.|\[/).length - 1,
        key,
        path,
        display,
        type,
        example,
        count,
    });

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
            records: null,
            scalars: 4,
            arrays: 1,
            objects: 4,
            nodes: noNodes
                ? []
                : [
                      node(0, null, null, '$', '$', 'object', null),
                      node(1, 0, 'data', '$.data', 'data', 'object', null),
                      node(
                          2,
                          1,
                          'summary',
                          '$.data.summary',
                          'data.summary',
                          'object',
                          null,
                      ),
                      node(
                          3,
                          2,
                          'kpis',
                          '$.data.summary.kpis',
                          'data.summary.kpis',
                          'object',
                          null,
                      ),
                      node(
                          4,
                          3,
                          'total',
                          '$.data.summary.kpis.total',
                          'data.summary.kpis.total',
                          'object',
                          null,
                      ),
                      node(
                          5,
                          4,
                          'amount',
                          '$.data.summary.kpis.total.amount',
                          'data.summary.kpis.total.amount',
                          'decimal',
                          '284680.5',
                      ),
                      node(
                          6,
                          2,
                          'currency',
                          '$.data.summary.currency',
                          'data.summary.currency',
                          'string',
                          'USD',
                      ),
                      node(
                          7,
                          1,
                          'monthly',
                          '$.data.monthly',
                          'data.monthly',
                          'array',
                          null,
                          3,
                      ),
                      node(
                          8,
                          7,
                          '*',
                          '$.data.monthly[*]',
                          'data.monthly[]',
                          'object',
                          null,
                      ),
                      node(
                          9,
                          8,
                          'revenue',
                          '$.data.monthly[*].revenue',
                          'data.monthly[].revenue',
                          'decimal',
                          '12',
                      ),
                      node(
                          10,
                          0,
                          'name',
                          '$.name',
                          'name',
                          'string',
                          'Revenue',
                      ),
                  ],
            tables: {},
        },
    };
}

function draft(): WizardDraft {
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
    } as WizardDraft;
}

function slot(over: Partial<ChecklistSlot> & { slot: string }): ChecklistSlot {
    return {
        label: over.slot,
        group: 'KPI',
        required: false,
        state: 'optional_empty',
        confidence: null,
        reason: null,
        suggestion: null,
        path: null,
        display: null,
        example: null,
        ...over,
    };
}

const headline = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'headline_value',
        label: 'Headline value',
        required: true,
        state: 'auto_medium',
        confidence: 'medium',
        reason: '2 Values; picked the one not named previous_*',
        path: '$.total',
        display: 'total',
        example: '284680',
        ...over,
    });

const title = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'title',
        label: 'Title',
        group: 'HEADER',
        state: 'auto_high',
        confidence: 'high',
        path: '$.name',
        display: 'name',
        example: 'Revenue',
        ...over,
    });

function checklist(slots: ChecklistSlot[], baseline: unknown[] = []) {
    const groups = ['HEADER', 'KPI', 'META']
        .map((group) => ({
            group,
            slots: slots.filter((s) => s.group === group),
        }))
        .filter((g) => g.slots.length > 0);
    const states = slots.map((s) => s.state);
    const n = (...wanted: string[]) =>
        states.filter((s) => wanted.includes(s)).length;
    const blockers: { slot: string; reason: Blocker['reason'] }[] =
        slots.flatMap((s) =>
            s.state === 'required_missing'
                ? [{ slot: s.slot, reason: 'required_missing' }]
                : s.state === 'auto_low'
                  ? [{ slot: s.slot, reason: 'unconfirmed_low' }]
                  : s.state === 'type_mismatch'
                    ? [{ slot: s.slot, reason: 'type_mismatch' }]
                    : [],
        );

    return {
        groups,
        counts: {
            auto_mapped: n('auto_high', 'auto_medium', 'auto_low'),
            total: slots.length,
            confirmed: n('auto_high', 'confirmed', 'overridden'),
            needs_review: n('auto_medium', 'auto_low'),
            required_missing: n('required_missing'),
            type_mismatch: n('type_mismatch'),
        },
        blockers: [...blockers, ...extraBlockers],
        baseline,
        api_changed: apiChanged,
    };
}

type Reply = { status: number; json?: unknown };
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let revision = 1;
let current: ChecklistSlot[] = [];
let baseline: unknown[] = [];
let sampleStatus = 'ok';
let apiChanged: ApiChange[] = [];
let roleFields: Record<string, unknown>[] = [];
let fieldsReply: Reply = { status: 200, json: { data: { fields: [] } } };
let extraBlockers: { slot: string; reason: Blocker['reason'] }[] = [];
let checklistNull = false;
let candidates: Record<string, unknown>[] = [];
let datasetSuggestion: Record<string, unknown> | null = null;
let noNodes = false;
let lockReply: () => Reply;
let writeReply: ((call: Call) => Reply) | null = null;
let rerunSlots: (() => ChecklistSlot[]) | null = null;
let wrapper: VueWrapper | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const row = (key: string) =>
    $$('[data-test=slot-row]').find((r) => r.dataset.slotKey === key)!;
const polite = () =>
    document.querySelector('[data-announcer=polite]')?.textContent ?? '';
async function settle(): Promise<void> {
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await settle();
}

async function open(slots: ChecklistSlot[], extra: unknown[] = []) {
    current = slots;
    baseline = extra;
    window.history.replaceState(
        {},
        '',
        '/admin/blocks/create?draft=d-1&step=3',
    );
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await settle();
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
    sampleStatus = 'ok';
    writeReply = null;
    extraBlockers = [];
    apiChanged = [];
    roleFields = [];
    fieldsReply = { status: 200, json: { data: { fields: [] } } };
    checklistNull = false;
    candidates = [];
    datasetSuggestion = null;
    noNodes = false;
    rerunSlots = null;
    sessionStorage.clear();
    localStorage.clear();
    Object.defineProperty(window, 'innerWidth', {
        value: 1280,
        configurable: true,
        writable: true,
    });
    lockReply = () => ({ status: 200, json: { data: { enabled: false } } });
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                const method = init?.method ?? 'GET';
                let reply: Reply;

                if (/\/lock(\/|$)/.test(url)) {
                    reply = lockReply();
                } else {
                    const call: Call = {
                        url,
                        method,
                        body: init?.body ? JSON.parse(init.body) : null,
                    };

                    calls.push(call);

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
                        reply = {
                            status: 200,
                            json: {
                                data: {
                                    checklist:
                                        sampleStatus === 'ok' && !checklistNull
                                            ? checklist(current, baseline)
                                            : null,
                                    roles:
                                        sampleStatus === 'ok'
                                            ? {
                                                  record_path: null,
                                                  record_path_display: null,
                                                  needs_choice:
                                                      candidates.length > 1,
                                                  candidates,
                                                  fields: roleFields,
                                              }
                                            : null,
                                    dataset_suggestion: datasetSuggestion,
                                    slot_map: {},
                                    query_plan: null,
                                    draft_revision: revision,
                                    sample_revision: 1,
                                    plan_stale: false,
                                    sample: sampleStatus,
                                },
                            },
                        };
                    } else if (/\/mapping\/slots\/[a-z_]+\/fields$/.test(url)) {
                        reply = fieldsReply;
                    } else if (url.startsWith(`${BASE}/mapping/`)) {
                        if (writeReply) {
                            reply = writeReply(call);
                        } else {
                            revision += 1;

                            if (url.endsWith('/auto-map') && rerunSlots) {
                                current = rerunSlots();
                            }

                            const confirm = url.match(
                                /slots\/([a-z_]+)\/confirm$/,
                            );

                            if (confirm) {
                                current = current.map((s) =>
                                    s.slot === confirm[1]
                                        ? { ...s, state: 'confirmed' }
                                        : s,
                                );
                            }

                            reply = {
                                status: 200,
                                json: {
                                    data: {
                                        ...checklist(current, baseline),
                                        draft_revision: revision,
                                        sample_revision: 1,
                                        query_plan: null,
                                    },
                                },
                            };
                        }
                    } else if (url === `${BASE}/sample`) {
                        reply = { status: 200, json: { data: sampleRecord() } };
                    } else if (url === BASE && method === 'GET') {
                        reply = { status: 200, json: { data: draft() } };
                    } else if (url === BASE && method === 'PUT') {
                        reply = {
                            status: 200,
                            json: {
                                data: {
                                    ...draft(),
                                    revision: 9,
                                    step: 4,
                                    config: JSON.parse(init!.body!).config,
                                },
                            },
                        };
                    } else {
                        reply = { status: 200, json: { data: [] } };
                    }
                }

                return {
                    ok: reply.status < 400,
                    status: reply.status,
                    headers: { get: () => null },
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

const field = (
    over: Partial<PickerField> & { display: string },
): PickerField => ({
    kind: 'field',
    path: `$.${over.display}`,
    type: 'number',
    effective_type: 'number',
    role: 'measure',
    example: '1',
    compatible: true,
    ...over,
});

function offer(fields: PickerField[]): void {
    fieldsReply = { status: 200, json: { data: { slot: 'x', fields } } };
}

function answer(extra: Record<string, unknown> = {}): Reply {
    revision += 1;

    return {
        status: 200,
        json: {
            data: {
                ...checklist(current, baseline),
                draft_revision: revision,
                sample_revision: 1,
                query_plan: null,
                ...extra,
            },
        },
    };
}

const putCalls = (tail: string) =>
    calls.filter(
        (c) => c.method === 'PUT' && c.url === `${BASE}/mapping/${tail}`,
    );
const AMOUNT = '$.data.summary.kpis.total.amount';
const AMOUNT_DISPLAY = 'data.summary.kpis.total.amount';
const AGGREGATION = 'Needs aggregation (a later release).';
const TEXT_REASON = "Text field can't be a numeric headline.";

const treeFields = () => [
    field({
        display: AMOUNT_DISPLAY,
        path: AMOUNT,
        type: 'decimal',
        effective_type: 'number',
        example: '284680.5',
    }),
    field({
        display: 'data.summary.currency',
        path: '$.data.summary.currency',
        type: 'string',
        effective_type: 'text',
        role: 'label',
        example: 'USD',
        compatible: false,
        reason: TEXT_REASON,
    }),
    field({
        display: 'data.monthly[].revenue',
        path: '$.data.monthly[*].revenue',
        type: 'decimal',
        effective_type: 'number',
        example: '12',
        compatible: false,
        reason: AGGREGATION,
    }),
    field({
        display: 'name',
        path: '$.name',
        type: 'string',
        effective_type: 'text',
        role: 'label',
        example: 'Revenue',
        compatible: false,
        reason: TEXT_REASON,
    }),
];

const missingHeadline = () => [
    headline({
        state: 'required_missing',
        path: null,
        display: null,
        example: null,
        reason: null,
    }),
    title(),
];

const item = (path: string) =>
    document.querySelector<HTMLElement>(
        `[data-test=tree-item][data-path="${path}"]`,
    )!;
const items = () => $$('[data-test=tree-item]');
const shownPaths = () => items().map((i) => i.dataset.path);
const toggle = () => $('[data-test=tree-toggle]');
const treeSearch = () => $('[data-test=tree-search]') as HTMLInputElement;
const footer = () => text('[data-test=tree-footer]');
const fieldCalls = (slot = 'headline_value') =>
    calls.filter(
        (c) =>
            c.method === 'GET' &&
            c.url === `${BASE}/mapping/slots/${slot}/fields`,
    );

function key(target: Element, name: string): void {
    target.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: name,
            bubbles: true,
            cancelable: true,
        }),
    );
}

async function press2(target: Element, name: string): Promise<void> {
    key(target, name);
    await settle();
}

async function typeInto(input: HTMLInputElement, value: string): Promise<void> {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

async function openTree(slots = missingHeadline()): Promise<void> {
    offer(treeFields());
    await open(slots);
}

describe('Field tree: the disclosure', () => {
    it('is collapsed behind "Browse all fields" when no required slot is missing', async () => {
        offer(treeFields());
        await open([headline({ state: 'confirmed' }), title()]);

        expect(toggle()?.textContent).toContain(labels.treeBrowse);
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');
        expect($('[data-test=field-tree]')).toBeNull();

        await click(toggle());
        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
        expect($('[data-test=tree]')).not.toBeNull();
    });

    it('opens by itself when a required slot is missing', async () => {
        await openTree();

        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
        expect($('[data-test=tree]')).not.toBeNull();
    });

    it('"Choose from the field tree" on a required-missing row opens the tree for that slot and focuses its search', async () => {
        offer(treeFields());
        await open(missingHeadline());
        await click(toggle());
        expect($('[data-test=field-tree]')).toBeNull();

        await click(
            row('headline_value').querySelector('[data-test=choose-from-tree]'),
        );

        expect($('[data-test=field-tree]')).not.toBeNull();
        expect(($('[data-test=tree-slot]') as HTMLSelectElement).value).toBe(
            'headline_value',
        );
        expect(document.activeElement).toBe(treeSearch());
        expect(
            row('title').querySelector('[data-test=choose-from-tree]'),
        ).toBeNull();
    });

    it('is not rendered when the sample has expired or has no nodes', async () => {
        sampleStatus = 'expired';
        await open(missingHeadline());
        expect($('[data-test=tree-toggle]')).toBeNull();
        expect($('[data-test=field-tree]')).toBeNull();
        wrapper?.unmount();
        document.body.innerHTML = '';

        sampleStatus = 'ok';
        noNodes = true;
        await open(missingHeadline());
        expect($('[data-test=tree-toggle]')).toBeNull();
    });
});

describe('Field tree: structure and keyboard', () => {
    it('is an APG tree with levels, sizes, positions, aria-expanded on branches and one tab stop', async () => {
        await openTree();

        const tree = $('[data-test=tree]')!;

        expect(tree.getAttribute('role')).toBe('tree');
        // The root `$` is not a row: its children are the first level.
        expect(shownPaths()).toEqual(['$.data', '$.name']);

        const data = item('$.data');

        expect(data.getAttribute('role')).toBe('treeitem');
        expect(data.getAttribute('aria-level')).toBe('1');
        expect(data.getAttribute('aria-setsize')).toBe('2');
        expect(data.getAttribute('aria-posinset')).toBe('1');
        expect(data.getAttribute('aria-expanded')).toBe('false');
        expect(item('$.name').hasAttribute('aria-expanded')).toBe(false);
        expect(items().filter((i) => i.tabIndex === 0)).toHaveLength(1);

        await press2(data, 'ArrowRight');
        expect(data.getAttribute('aria-expanded')).toBe('true');
        expect(data.querySelector('[role=group]')).not.toBeNull();
        expect(item('$.data.summary').getAttribute('aria-level')).toBe('2');
        expect(item('$.data.monthly').getAttribute('aria-posinset')).toBe('2');
    });

    it('moves with Up/Down, opens with Right, goes to the first child, closes with Left, goes to the parent', async () => {
        await openTree();
        item('$.data').focus();

        await press2(item('$.data'), 'ArrowRight');
        expect(document.activeElement).toBe(item('$.data'));
        await press2(item('$.data'), 'ArrowRight');
        expect(document.activeElement).toBe(item('$.data.summary'));
        expect(item('$.data').tabIndex).toBe(-1);

        await press2(item('$.data.summary'), 'ArrowDown');
        expect(document.activeElement).toBe(item('$.data.monthly'));
        await press2(item('$.data.monthly'), 'ArrowUp');
        expect(document.activeElement).toBe(item('$.data.summary'));

        // A closed branch goes to its parent; an open one closes first.
        await press2(item('$.data.summary'), 'ArrowRight');
        await press2(item('$.data.summary'), 'ArrowLeft');
        expect(item('$.data.summary').getAttribute('aria-expanded')).toBe(
            'false',
        );
        await press2(item('$.data.summary'), 'ArrowLeft');
        expect(document.activeElement).toBe(item('$.data'));
    });

    it('Home and End go to the first and last shown row', async () => {
        await openTree();
        item('$.data').focus();

        await press2(item('$.data'), 'End');
        expect(document.activeElement).toBe(item('$.name'));
        await press2(item('$.name'), 'Home');
        expect(document.activeElement).toBe(item('$.data'));
    });

    it('type-ahead jumps to the next row that starts with the letters', async () => {
        await openTree();
        item('$.data').focus();

        await press2(item('$.data'), 'n');
        expect(document.activeElement).toBe(item('$.name'));
    });

    it('Expand all and Collapse all fold every branch', async () => {
        await openTree();
        await click($('[data-test=tree-expand-all]'));
        expect(shownPaths()).toContain(AMOUNT);
        expect(shownPaths()).toContain('$.data.monthly[*].revenue');

        await click($('[data-test=tree-collapse-all]'));
        expect(shownPaths()).toEqual(['$.data', '$.name']);
    });

    it('shows a breadcrumb for the focused node', async () => {
        await openTree();
        await click($('[data-test=tree-expand-all]'));

        expect(
            $('[data-test=tree-breadcrumb]')?.getAttribute('aria-label'),
        ).toBe(labels.treeBreadcrumb);
        item(AMOUNT).focus();
        await settle();

        expect(
            $$('[data-test=crumb]').map((c) =>
                c.textContent?.replace(/[›\s]+/g, ' ').trim(),
            ),
        ).toEqual(['data', 'summary', 'kpis', 'total', 'amount']);
        expect($$('[data-test=crumb]')[4].getAttribute('aria-current')).toBe(
            'location',
        );
    });

    it('shows a type chip (type also as text) and a mono example on leaves', async () => {
        await openTree();
        await click($('[data-test=tree-expand-all]'));

        const amount = item(AMOUNT);
        const chipEl = amount.querySelector('[data-test=tree-type]')!;

        expect(chipEl.textContent).toBe('number');
        expect(chipEl.className).toContain('role-measure');
        expect(
            amount.querySelector('[data-test=tree-example]')?.textContent,
        ).toBe('284680.5');
        expect(
            item('$.name').querySelector('[data-test=tree-type]')?.textContent,
        ).toBe('text');
        expect(
            item('$.name').querySelector('[data-test=tree-type]')?.className,
        ).toContain('role-label');
        expect(
            item('$.data.monthly').querySelector('[data-test=tree-type]')
                ?.className,
        ).toContain('role-filter');
        expect(
            item('$.data').querySelector('[data-test=tree-type]')?.textContent,
        ).toBe('object');
    });
});

describe('Field tree: search', () => {
    it('keeps matching leaves and their ancestors, opens the branches and announces the count politely', async () => {
        await openTree();
        await typeInto(treeSearch(), 'amount');
        await new Promise((resolve) => setTimeout(resolve, 700));

        expect(shownPaths()).toEqual([
            '$.data',
            '$.data.summary',
            '$.data.summary.kpis',
            '$.data.summary.kpis.total',
            AMOUNT,
        ]);
        expect(item('$.data').getAttribute('aria-expanded')).toBe('true');
        expect(polite()).toContain('1 field matches');
    });

    it('says so when nothing matches', async () => {
        await openTree();
        await typeInto(treeSearch(), 'zzzz');

        expect($('[data-test=tree]')).toBeNull();
        expect(text('[data-test=tree-empty]')).toBe(labels.treeEmpty);
    });

    it('ArrowDown from the search box goes into the tree', async () => {
        await openTree();
        treeSearch().focus();
        await press2(treeSearch(), 'ArrowDown');

        expect(document.activeElement).toBe(item('$.data'));
    });
});

describe('Field tree: compatibility and Use', () => {
    async function expandAll(): Promise<void> {
        await click($('[data-test=tree-expand-all]'));
    }

    it('marks an incompatible leaf aria-disabled with the server reason in aria-describedby; Enter sends nothing', async () => {
        await openTree();
        await expandAll();

        const name = item('$.name');
        const reason = name.querySelector('[data-test=tree-reason]')!;

        expect(name.getAttribute('aria-disabled')).toBe('true');
        expect(reason.textContent).toBe(TEXT_REASON);
        expect(name.getAttribute('aria-describedby')).toBe(reason.id);
        expect(name.querySelector('[data-test=tree-use]')).toBeNull();

        // Fields inside the records read the aggregation reason.
        expect(
            item('$.data.monthly[*].revenue').querySelector(
                '[data-test=tree-reason]',
            )?.textContent,
        ).toBe(AGGREGATION);

        name.focus();
        await press2(name, 'Enter');
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
        expect(polite()).toContain(TEXT_REASON);
    });

    it('Enter on a compatible leaf overrides the headline through the step flow and the footer shows the selection', async () => {
        await openTree();
        await expandAll();
        writeReply = () => {
            current = [
                headline({
                    state: 'overridden',
                    path: AMOUNT,
                    display: AMOUNT_DISPLAY,
                    example: '284680.5',
                }),
                title(),
            ];

            return answer({
                undo: { slot: 'headline_value' },
            });
        };

        const amount = item(AMOUNT);

        amount.focus();
        await settle();
        // Focus alone selects nothing: the footer says "Focused" until Enter or Use.
        expect(footer()).toBe(
            `Focused: ${AMOUNT_DISPLAY} · 284680.5 → 284,680.5`,
        );
        expect(amount.getAttribute('aria-selected')).toBe('false');

        await press2(amount, 'Enter');

        const put = putCalls('slots/headline_value')[0];

        expect(put.body).toMatchObject({ path: AMOUNT, draft_revision: 1 });
        expect(put.body).toHaveProperty('lock_epoch');
        expect(row('headline_value').dataset.state).toBe('overridden');
        expect(text('[data-test=override-note]')).toContain(labels.undo);
        expect(polite()).toContain('Headline value now uses');
        expect(footer()).toBe(
            `Selected: ${AMOUNT_DISPLAY} · 284680.5 → 284,680.5`,
        );
        expect(item(AMOUNT).getAttribute('aria-selected')).toBe('true');
    });

    it('the Use button does the same and is named for the field and the slot', async () => {
        await openTree();
        await expandAll();
        writeReply = () => {
            current = [
                headline({
                    state: 'overridden',
                    path: AMOUNT,
                    display: AMOUNT_DISPLAY,
                }),
                title(),
            ];

            return answer();
        };

        const use = item(AMOUNT).querySelector<HTMLElement>(
            '[data-test=tree-use]',
        )!;

        expect(use.getAttribute('aria-label')).toBe(
            labels.treeUseAria(AMOUNT_DISPLAY, 'Headline value'),
        );
        await click(use);

        expect(putCalls('slots/headline_value')).toHaveLength(1);
        expect(row('headline_value').dataset.state).toBe('overridden');
    });

    it('"Use for" defaults to the first required unmapped slot and a change re-reads that slot\'s fields', async () => {
        await openTree();

        const select = $('[data-test=tree-slot]') as HTMLSelectElement;

        expect(select.value).toBe('headline_value');
        expect(fieldCalls('headline_value').length).toBeGreaterThan(0);

        select.value = 'title';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        expect(fieldCalls('title')).toHaveLength(1);
    });

    it('does nothing while a write is pending: Use is disabled', async () => {
        await openTree();
        await expandAll();

        let release: () => void = () => {};
        let held = 0;
        const gate = new Promise<void>((resolve) => (release = resolve));
        const original = globalThis.fetch;

        vi.stubGlobal('fetch', async (url: string, init?: RequestInit) => {
            if (
                String(url).includes('/slots/headline_value') &&
                init?.method === 'PUT'
            ) {
                held += 1;
                await gate;
            }

            return (original as typeof fetch)(url, init);
        });

        const amount = item(AMOUNT);

        amount.focus();
        key(amount, 'Enter');
        await settle();
        key(amount, 'Enter');
        await settle();
        expect(held).toBe(1);
        expect(
            item(AMOUNT)
                .querySelector('[data-test=tree-use]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');

        release();
        await settle();
    });

    it('says why when the write is refused and keeps the tree', async () => {
        await openTree();
        await expandAll();
        writeReply = () => ({
            status: 422,
            json: {
                errors: { 'slot.headline_value': ['That field does not fit.'] },
            },
        });

        const amount = item(AMOUNT);

        amount.focus();
        await press2(amount, 'Enter');

        expect(text('[data-test=map-not-saved]')).toBe(
            'That field does not fit.',
        );
        expect($('[data-test=tree]')).not.toBeNull();
    });
});

describe('Field tree: read-only', () => {
    it('browses without Use, states the reason, and does not read the slot fields', async () => {
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
        await openTree();

        expect($('[data-test=tree]')).not.toBeNull();
        expect($('[data-test=tree-blocked]')).not.toBeNull();
        expect($('[data-test=tree-slot]')).toBeNull();

        await click($('[data-test=tree-expand-all]'));
        expect($$('[data-test=tree-use]')).toHaveLength(0);
        expect(fieldCalls()).toHaveLength(0);

        item(AMOUNT).focus();
        await press2(item(AMOUNT), 'Enter');
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    });
});

describe('Datasets suggestion in the record-path list', () => {
    const candidate = (path: string, display: string, suggested = false) => ({
        path,
        display,
        rows: 3,
        columns: ['a'],
        depth: 2,
        suggested,
        pagination_like: false,
    });
    const suggestion = (path: string, display: string) => ({
        record_path: path,
        record_path_display: display,
        fields: [{ path: `${path}.a`, type: 'number', role: 'measure' }],
        updated_at: '2026-10-09T09:00:00Z',
    });

    it('marks the matching candidate as used in an earlier block, in text, and selects no radio', async () => {
        candidates = [
            candidate('$.data.monthly[*]', 'data.monthly[]', true),
            candidate('$.other[*]', 'other[]'),
        ];
        datasetSuggestion = suggestion('$.other[*]', 'other[]');
        await openTree();

        const options = $$('[data-test=record-path-option]');
        const marks = options.map(
            (o) =>
                o.querySelector('[data-test=record-path-earlier]')
                    ?.textContent ?? null,
        );

        expect(marks).toEqual([null, labels.datasetUsedEarlier]);
        expect(
            $$('[data-test=record-path-group] [role=radio]').filter(
                (r) => r.getAttribute('aria-checked') === 'true',
            ),
        ).toHaveLength(0);
        expect($('[data-test=record-path-earlier-note]')).toBeNull();
        expect(puts().length).toBe(0);
    });

    it('shows a one-line note when the suggestion is not among the candidates', async () => {
        candidates = [
            candidate('$.data.monthly[*]', 'data.monthly[]'),
            candidate('$.other[*]', 'other[]'),
        ];
        datasetSuggestion = suggestion('$.gone[*]', 'gone[]');
        await openTree();

        expect($$('[data-test=record-path-earlier]')).toHaveLength(0);
        expect(text('[data-test=record-path-earlier-note]')).toBe(
            labels.datasetNotCandidate('gone[]'),
        );
        expect(puts().length).toBe(0);
    });

    it('shows nothing when there is no suggestion', async () => {
        candidates = [
            candidate('$.data.monthly[*]', 'data.monthly[]'),
            candidate('$.other[*]', 'other[]'),
        ];
        await openTree();

        expect($$('[data-test=record-path-earlier]')).toHaveLength(0);
        expect($('[data-test=record-path-earlier-note]')).toBeNull();
    });
});

describe('Field tree: listing, selection, search and focus', () => {
    async function expandAll(): Promise<void> {
        await click($('[data-test=tree-expand-all]'));
    }

    function hold(match: RegExp): () => void {
        let release: () => void = () => {};
        const gate = new Promise<void>((resolve) => (release = resolve));
        const original = globalThis.fetch;

        vi.stubGlobal('fetch', async (url: string, init?: RequestInit) => {
            if (match.test(String(url))) {
                await gate;
            }

            return (original as typeof fetch)(url, init);
        });

        return release;
    }

    it('switching "Use for" drops the old fit data: no Use and Enter says it is still checking, until the new listing arrives', async () => {
        await openTree();
        await expandAll();
        expect($$('[data-test=tree-use]').length).toBeGreaterThan(0);

        const release = hold(/slots\/title\/fields$/);
        const select = $('[data-test=tree-slot]') as HTMLSelectElement;

        select.value = 'title';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        expect($$('[data-test=tree-use]')).toHaveLength(0);

        item(AMOUNT).focus();
        await press2(item(AMOUNT), 'Enter');
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
        expect(polite()).toContain(labels.treeStillLoading);

        release();
        await settle();
        expect($$('[data-test=tree-use]').length).toBeGreaterThan(0);
    });

    it('a mapping write re-reads the slot listing', async () => {
        await openTree();
        await expandAll();
        expect(fieldCalls()).toHaveLength(1);
        writeReply = () => {
            current = [
                headline({
                    state: 'overridden',
                    path: AMOUNT,
                    display: AMOUNT_DISPLAY,
                }),
                title(),
            ];

            return answer();
        };

        item(AMOUNT).focus();
        await press2(item(AMOUNT), 'Enter');

        expect(fieldCalls()).toHaveLength(2);
    });

    it('asking again for the same slot after changing "Use for" switches back to it', async () => {
        await openTree();

        const select = $('[data-test=tree-slot]') as HTMLSelectElement;

        select.value = 'title';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await settle();
        expect(select.value).toBe('title');

        await click(
            row('headline_value').querySelector('[data-test=choose-from-tree]'),
        );
        expect(($('[data-test=tree-slot]') as HTMLSelectElement).value).toBe(
            'headline_value',
        );
    });

    it('offers Retry when the listing fails, and Enter says why nothing happened', async () => {
        offer(treeFields());
        fieldsReply = { status: 500, json: {} };
        await open(missingHeadline());
        await expandAll();

        expect($('[data-test=tree-retry]')).not.toBeNull();
        item(AMOUNT).focus();
        await press2(item(AMOUNT), 'Enter');
        expect(polite()).toContain(labels.treeFailed);

        offer(treeFields());
        await click($('[data-test=tree-retry]'));
        expect($('[data-test=tree-retry]')).toBeNull();
        expect($$('[data-test=tree-use]').length).toBeGreaterThan(0);
    });

    it('announces that a change is being saved when Enter is pressed during a write', async () => {
        await openTree();
        await expandAll();

        const release = hold(/slots\/headline_value$/);

        item(AMOUNT).focus();
        key(item(AMOUNT), 'Enter');
        await settle();
        key(item(AMOUNT), 'Enter');
        await settle();
        expect(polite()).toContain(labels.treeBusyNow);

        release();
        await settle();
    });

    it('search matches the displayed type word, and clearing it restores the earlier folding', async () => {
        await openTree();
        await click(item('$.data').querySelector('[data-test=tree-row]'));
        expect(shownPaths()).toContain('$.data.summary');
        expect(shownPaths()).not.toContain(AMOUNT);

        await typeInto(treeSearch(), 'number');
        expect(shownPaths()).toContain(AMOUNT);

        await typeInto(treeSearch(), '');
        expect(shownPaths()).toContain('$.data.summary');
        expect(shownPaths()).not.toContain(AMOUNT);
    });

    it('moves focus to the nearest visible row when Collapse all removes the focused one', async () => {
        await openTree();
        await expandAll();
        item(AMOUNT).focus();
        await settle();

        await click($('[data-test=tree-collapse-all]'));

        expect(document.activeElement).toBe(item('$.data'));
        expect(item('$.data').tabIndex).toBe(0);
    });

    it('hides "Choose from the field tree" when the tree is unavailable', async () => {
        noNodes = true;
        await openTree();

        expect(
            row('headline_value').querySelector('[data-test=choose-from-tree]'),
        ).toBeNull();
    });

    it('joins a listing relative to the record path to the absolute nodes', () => {
        const entry = (path: string) =>
            ({
                kind: 'field',
                path,
                display: path,
                compatible: false,
                reason: AGGREGATION,
            }) as PickerField;
        const relative = listingOf(
            [entry('$.revenue'), entry('$.name')],
            '$.data.monthly[*]',
        );

        expect(relative.get('$.data.monthly[*].revenue')?.reason).toBe(
            AGGREGATION,
        );

        // Already absolute: nothing is derived.
        const absolute = listingOf(
            [entry('$.data.monthly[*].revenue'), entry('$.name')],
            '$.data.monthly[*]',
        );

        expect(absolute.has('$.data.monthly[*].name')).toBe(false);
        expect(listingOf([entry('$.name')], null).size).toBe(1);
    });
});

describe('Datasets suggestion: chosen path and form', () => {
    it('marks `$.other[*]` for a suggestion `$.other` with no note, and hides both once a path is chosen', async () => {
        candidates = [
            {
                path: '$.data.monthly[*]',
                display: 'data.monthly[]',
                rows: 3,
                columns: ['a'],
                depth: 2,
                suggested: true,
                pagination_like: false,
            },
            {
                path: '$.other[*]',
                display: 'other[]',
                rows: 3,
                columns: ['a'],
                depth: 2,
                suggested: false,
                pagination_like: false,
            },
        ];
        datasetSuggestion = {
            record_path: '$.other',
            record_path_display: 'other',
            fields: [],
            updated_at: '2026-10-09T09:00:00Z',
        };
        await openTree();

        expect($$('[data-test=record-path-earlier]')).toHaveLength(1);
        expect($('[data-test=record-path-earlier-note]')).toBeNull();

        await click($$('[data-test=record-path-group] [role=radio]')[0]);

        expect($$('[data-test=record-path-earlier]')).toHaveLength(0);
    });
});

function puts(): Call[] {
    return calls.filter((c) => c.method === 'PUT');
}

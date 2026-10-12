// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type {
    ApiChange,
    Blocker,
    ChecklistSlot,
} from '../../resources/js/lib/blockMapping';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 5.1: the Transforms section of wizard step 3 (Filter, Sort and Top-N steps), wired to the mapping endpoints and to Continue.
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
let lockReply: () => Reply;
let writeReply: ((call: Call) => Reply) | null = null;
let rerunSlots: (() => ChecklistSlot[]) | null = null;
let wrapper: VueWrapper | null = null;
let stored: Record<string, unknown> = {};
let presentationReply: ((call: Call) => Reply) | null = null;
let storedTransforms: Record<string, unknown>[] = [];
let transformFaults: Record<string, unknown>[] = [];
let transformsReply: ((call: Call) => Reply) | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
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
    rerunSlots = null;
    stored = {};
    presentationReply = null;
    storedTransforms = [];
    transformFaults = [];
    transformsReply = null;
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
                                            ? {
                                                  ...checklist(
                                                      current,
                                                      baseline,
                                                  ),
                                                  transform_faults:
                                                      transformFaults,
                                              }
                                            : null,
                                    roles:
                                        sampleStatus === 'ok'
                                            ? {
                                                  record_path: null,
                                                  record_path_display: null,
                                                  needs_choice: false,
                                                  candidates: [],
                                                  fields: roleFields,
                                              }
                                            : null,
                                    slot_map: {},
                                    presentation: stored,
                                    transforms: storedTransforms,
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
                    } else if (url === `${BASE}/mapping/transforms`) {
                        if (transformsReply) {
                            reply = transformsReply(call);
                        } else {
                            revision += 1;
                            storedTransforms = call.body!.transforms;
                            reply = {
                                status: 200,
                                json: {
                                    data: {
                                        transforms: storedTransforms,
                                        draft_revision: revision,
                                        query_plan: null,
                                        transform_faults: transformFaults,
                                    },
                                },
                            };
                        }
                    } else if (url === `${BASE}/mapping/presentation`) {
                        if (presentationReply) {
                            reply = presentationReply(call);
                        } else {
                            revision += 1;
                            stored = call.body!.presentation;
                            reply = {
                                status: 200,
                                json: {
                                    data: {
                                        presentation: stored,
                                        draft_revision: revision,
                                        query_plan: null,
                                    },
                                },
                            };
                        }
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
                        reply = { status: 200, json: { data: null } };
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

const comparison = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'comparison',
        label: 'Comparison value or change %',
        value_type: 'number',
        state: 'auto_high',
        confidence: 'high',
        path: '$.change',
        display: 'change',
        example: '4.2',
        ...over,
    });

async function setValue(
    element: HTMLElement | null,
    value: string,
    event = 'change',
): Promise<void> {
    const input = element as HTMLInputElement | HTMLSelectElement;

    input.value = value;
    input.dispatchEvent(new Event(event, { bubbles: true }));
    await settle();
}

const mapped = () => [
    headline({ state: 'confirmed', value_type: 'number' }),
    comparison(),
];

const rowField = (path: string, display: string, type = 'text') => ({
    path,
    display,
    type,
    example: null,
    role: 'label',
    confidence: 'high',
    overridden: false,
    in_records: true,
    suggestion: null,
});

const transformPuts = () =>
    calls.filter(
        (c) => c.method === 'PUT' && c.url === `${BASE}/mapping/transforms`,
    );

async function openWithRows() {
    roleFields = [
        rowField('$.data[*].status', 'data[].status'),
        rowField('$.data[*].revenue', 'data[].revenue', 'integer'),
    ];
    await open(mapped());
}

describe('Transforms section', () => {
    it('is a collapsible section "3 · Transforms" that starts closed with no steps', async () => {
        await openWithRows();

        const section = $('[data-test=transforms-section]')!;

        expect(section.textContent).toContain('3 · Transforms');
        expect(section.hasAttribute('open')).toBe(false);
        expect($$('[data-test=transform-row]')).toHaveLength(0);
    });

    it('adds a Filter step, sends the steps whole and shows the sentence the server stored', async () => {
        await openWithRows();

        await click($('[data-test=add-step]'));
        await click($('[data-test=add-filter]'));

        expect(transformPuts()).toHaveLength(1);
        expect(transformPuts()[0].body).toMatchObject({
            transforms: [
                {
                    type: 'filter',
                    field: '$.data[*].status',
                    operator: 'is',
                    value: '',
                },
            ],
            draft_revision: 1,
        });

        const value = $('[data-test=filter-value]') as HTMLInputElement;

        await setValue(value, 'Approved');

        expect(transformPuts()).toHaveLength(2);
        expect(text('[data-test=step-sentence]')).toBe(
            'Keep rows where status is Approved',
        );
    });

    it('keeps the fixed stage order when a Top N is added before a Filter', async () => {
        await openWithRows();

        await click($('[data-test=add-step]'));
        await click($('[data-test=add-top_n]'));
        await click($('[data-test=add-step]'));
        await click($('[data-test=add-filter]'));

        expect(
            transformPuts()
                .at(-1)!
                .body!.transforms.map((s: { type: string }) => s.type),
        ).toEqual(['filter', 'top_n']);
        expect($$('[data-test=stage-label]').map((e) => e.textContent)).toEqual(
            ['FILTER', 'TOP N'],
        );
    });

    it('does not send a numeric comparison with no number, and blocks Continue naming the reason', async () => {
        storedTransforms = [
            {
                type: 'filter',
                field: '$.data[*].revenue',
                operator: 'gt',
                value: '10',
            },
        ];
        await openWithRows();

        await setValue($('[data-test=filter-value]'), 'abc');

        expect(transformPuts()).toHaveLength(0);
        expect(text('[data-test=step-fault]')).toContain('Enter a number');
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=slots-continue-reason]')).toContain(
            'transform step',
        );
    });

    it('shows a step the sample cannot satisfy and moves focus to it when Continue is activated', async () => {
        storedTransforms = [
            {
                type: 'filter',
                field: '$.data[*].gone',
                operator: 'is',
                value: 'x',
            },
        ];
        transformFaults = [
            {
                index: 0,
                type: 'filter',
                field: '$.data[*].gone',
                reason: 'field_missing',
            },
        ];
        await openWithRows();

        expect(text('[data-test=step-fault]')).toContain(
            'isn’t in the response',
        );
        expect($('[data-test=transform-row]')?.getAttribute('data-fault')).toBe(
            'true',
        );

        await click($('[data-test=slots-continue]'));

        expect(
            document.activeElement?.closest('[data-test=transform-row]'),
        ).not.toBeNull();
    });

    it('removes a step and puts the stored list back through the server', async () => {
        storedTransforms = [{ type: 'top_n', count: 3 }];
        await openWithRows();

        await click($('[data-test=remove-step]'));

        expect(transformPuts().at(-1)!.body).toMatchObject({ transforms: [] });
        expect($$('[data-test=transform-row]')).toHaveLength(0);
    });

    it('is read-only, with no control that changes anything, when another editor holds the lock', async () => {
        storedTransforms = [{ type: 'top_n', count: 3 }];
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
        await openWithRows();

        expect($('[data-test=add-step]')?.getAttribute('aria-disabled')).toBe(
            'true',
        );
        expect(($('[data-test=topn-count]') as HTMLInputElement).disabled).toBe(
            true,
        );
        expect(
            $('[data-test=remove-step]')?.getAttribute('aria-disabled'),
        ).toBe('true');
    });
});

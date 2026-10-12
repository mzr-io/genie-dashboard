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

// Story 3.16: Step 4, Preview on every device and state, with warning acknowledgements.
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
let storedAck: string[] = [];
let stepNow = 4;
let sourceNow: Record<string, string> | undefined;

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
        step: stepNow,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: {
            schema_version: 1,
            description: 'Daily revenue',
            ...(sourceNow ? { source: sourceNow } : {}),
        },
        ui_state: { acknowledged_warnings: storedAck, slot_statuses: {} },
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

const note = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'secondary_note',
        label: 'Secondary note',
        group: 'META',
        state: 'auto_medium',
        confidence: 'medium',
        reason: 'The key says note',
        path: '$.note',
        display: 'note',
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
type Call = {
    url: string;
    method: string;
    body: Record<string, any> | null;
    headers: Record<string, string>;
};
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
let previewReply: () => Reply;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

async function settle(): Promise<void> {
    await flushPromises();
    if (vi.isFakeTimers()) {
        await vi.advanceTimersByTimeAsync(0);
    } else {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
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
        '/admin/blocks/create?draft=d-1&step=4',
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
    previewReply = () => ({ status: 200, json: { data: preview() } });
    storedAck = [];
    stepNow = 4;
    sourceNow = undefined;
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
                    const call: Call = {
                        url,
                        method,
                        body: init?.body ? JSON.parse(init.body) : null,
                        headers: (init as any)?.headers ?? {},
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
                                                  needs_choice: false,
                                                  candidates: [],
                                                  fields: roleFields,
                                              }
                                            : null,
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
                    } else if (url === `${BASE}/preview`) {
                        reply = previewReply();
                    } else if (url === `${BASE}/sample`) {
                        reply = { status: 200, json: { data: null } };
                    } else if (
                        url === `${BASE}/validation` &&
                        method === 'POST'
                    ) {
                        reply = {
                            status: 202,
                            json: {
                                data: {
                                    operation_id: 'o-1',
                                    status: 'queued',
                                    expires_at: '2026-10-12T09:20:00Z',
                                    draft_revision: 9,
                                },
                            },
                        };
                    } else if (url === `${BASE}/validation`) {
                        reply = { status: 200, json: { data: null } };
                    } else if (url.startsWith('/api/v1/operations/')) {
                        reply = {
                            status: 200,
                            json: {
                                data: {
                                    id: 'o-1',
                                    kind: 'publish_validation',
                                    status: 'failed',
                                    result: { ok: false, report_id: null },
                                    expires_at: '2026-10-12T09:20:00Z',
                                },
                            },
                        };
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
                                    ui_state: JSON.parse(init!.body!).ui_state,
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
    vi.useRealTimers();
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const fmt = {
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
    datePattern: 'medium',
    unitsSuffix: null,
};

// What the engine answers: the payload the Shaper made, plus each slot's value and the revisions.
function preview(over: Record<string, unknown> = {}, value = '284680') {
    return {
        payload: {
            state: 'normal',
            title: 'From the server',
            subtitle: null,
            icon: null,
            footerAction: null,
            kpiLabel: 'Revenue',
            headline: { available: true, value, format: fmt },
            comparison: null,
            comparisonLabel: null,
            secondaryNote: 'Updated daily',
            statusBadge: null,
            staleSince: null,
            errorMessage: null,
        },
        unavailable: {},
        slots: {
            headline_value: { value, path: '$.total' },
            kpi_label: { value: 'Revenue', path: '$.label' },
            secondary_note: { value: 'Updated daily', path: '$.note' },
        },
        sample_revision: 1,
        draft_revision: revision,
        plan_stale: false,
        ...over,
    };
}

const kpiLabel = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'kpi_label',
        label: 'KPI label',
        required: true,
        group: 'KPI',
        state: 'auto_high',
        confidence: 'high',
        path: '$.label',
        display: 'label',
        example: 'Revenue',
        ...over,
    });
const mapped = () => [
    headline({ state: 'confirmed' }),
    kpiLabel(),
    note({ state: 'auto_high', confidence: 'high' }),
];
const previews = () =>
    calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/preview`);
const cleared = () =>
    note({
        state: 'optional_empty',
        confidence: null,
        reason: 'Cleared by an admin.',
        path: null,
        display: null,
    });
const warnings = () => $$('[data-test=warning]');
const continueButton = () => $('[data-test=continue]')!;
const putBody = () =>
    calls.filter((c) => c.url === BASE && c.method === 'PUT').at(-1)!.body!;

describe('Step 4: the Block on every device', () => {
    it('opens with Desktop, the full size Block, the 12-column grid and the Sample data label', async () => {
        await open(mapped());

        expect($('[data-test=step-4]')).not.toBeNull();
        expect($('[data-test=later-step]')).toBeNull();
        expect($('[data-test=preview-column]')).toBeNull();
        expect(text('[data-test=preview-sample-label]')).toBe('Sample data');
        expect($('[data-test=preview-canvas]')!.dataset.device).toBe('desktop');
        expect(
            $('[data-test=block-full] [data-test=kpi-headline]'),
        ).not.toBeNull();
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );

        const grid = $('[data-test=grid-canvas]')!;

        expect(grid.dataset.columns).toBe('12');
        expect(grid.dataset.gap).toBe('14');
        expect(grid.style.gap).toBe('14px');
        expect(grid.style.gridTemplateColumns).toContain('repeat(12');
        // The Block takes its configured width (3 columns by default).
        expect($('[data-test=grid-block]')!.dataset.span).toBe('3');
        expect(
            $('[data-test=grid-block] [data-test=kpi-headline]'),
        ).not.toBeNull();
    });

    it('renders Mobile at mobile width without page scroll, the content scrolling inside the Block', async () => {
        await open(mapped());

        const mobile = $$('[role=radio]').find(
            (r) => r.textContent?.trim() === 'Mobile',
        )!;

        await click(mobile);

        const canvas = $('[data-test=preview-canvas]')!;

        expect(canvas.dataset.device).toBe('mobile');
        expect(canvas.style.width).toBe('375px');
        expect(canvas.style.maxWidth).toBe('100%');
        expect($('[data-test=grid-block]')!.dataset.span).toBe('12');
        expect($('[data-test=block-full]')!.className).toContain(
            'overflow-hidden',
        );
        expect($('[data-test=kpi-body]')!.className).toContain('overflow-auto');
        expect($('[data-test=preview-region]')!.className).not.toContain(
            'overflow-x',
        );
    });

    it('shows each state from the Renderer, and Unavailable never shows $0', async () => {
        await open(mapped());

        const pick = async (label: string) =>
            click(
                $$('[role=radio]').find(
                    (r) => r.textContent?.trim() === label,
                ) ?? null,
            );

        await pick('Unavailable');
        expect(text('[data-test=block-full] [data-test=kpi-unavailable]')).toBe(
            "Unavailable — this value can't be shown right now.",
        );
        expect($('[data-test=block-full]')!.textContent).not.toContain('$0');
        expect($('[data-test=block-full] [data-test=kpi-headline]')).toBeNull();

        await pick('Loading');
        expect(
            $('[data-test=block-full] [data-test=kpi-loading]'),
        ).not.toBeNull();

        await pick('Empty');
        expect(
            $('[data-test=block-full] [data-test=kpi-empty]'),
        ).not.toBeNull();

        await pick('Stale');
        expect(
            $('[data-test=block-full] [data-test=kpi-stale]'),
        ).not.toBeNull();

        await pick('Minimized');
        expect($('[data-test=block-minimized]')).not.toBeNull();
        expect($('[data-test=block-full] [data-test=kpi-card]')).toBeNull();

        await pick('Normal');
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );
    });
});

describe('Step 4: warnings and acknowledgements', () => {
    it('has no warnings and an enabled Continue when there is nothing to review', async () => {
        await open(mapped());

        expect($('[data-test=no-warnings]')).not.toBeNull();
        expect(warnings()).toHaveLength(0);
        expect(continueButton().getAttribute('aria-disabled')).toBeNull();
    });

    it('lists a cleared optional slot and blocks Continue until it is acknowledged, then stores it', async () => {
        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);

        expect(warnings()).toHaveLength(1);
        expect(warnings()[0].dataset.kind).toBe('cleared_optional');
        expect(continueButton().getAttribute('aria-disabled')).toBe('true');
        expect(text('[data-test=continue-reason]')).toBe(
            'Acknowledge 1 warning to continue.',
        );

        await click($('[data-test=warning-ack]'));

        expect(continueButton().getAttribute('aria-disabled')).toBeNull();
        expect($('[data-test=continue-reason]')).toBeNull();

        await click(continueButton());

        const body = putBody();

        expect(body.step).toBe(5);
        expect(body.ui_state.acknowledged_warnings).toHaveLength(1);
        expect(body.ui_state.acknowledged_warnings[0]).toMatch(
            /^w:cleared_optional:secondary_note:/,
        );
        expect($('[data-test=validate-step]')).not.toBeNull();
        expect($('[data-test=later-step]')).toBeNull();
    });

    it('keeps the acknowledgement after a reload', async () => {
        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);
        await click($('[data-test=warning-ack]'));
        await click($('[data-test=save-draft]'));

        const saved = putBody().ui_state.acknowledged_warnings as string[];

        expect(saved).toHaveLength(1);

        wrapper!.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        storedAck = saved;

        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);

        expect($('[data-test=warning-ack]')!.getAttribute('aria-checked')).toBe(
            'true',
        );
        expect(continueButton().getAttribute('aria-disabled')).toBeNull();
    });

    it('resets the acknowledgement of a warning that changed and drops one that is gone', async () => {
        storedAck = [
            'w:cleared_optional:secondary_note:old',
            'w:null_value:x:y',
        ];
        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);

        expect($('[data-test=warning-ack]')!.getAttribute('aria-checked')).toBe(
            'false',
        );
        expect(continueButton().getAttribute('aria-disabled')).toBe('true');

        await click($('[data-test=save-draft]'));

        expect(putBody().ui_state.acknowledged_warnings).toEqual([]);
    });

    it('warns about a null in the sample for an optional slot', async () => {
        previewReply = () => ({
            status: 200,
            json: {
                data: preview({
                    slots: {
                        headline_value: { value: '284680', path: '$.total' },
                        kpi_label: { value: 'Revenue', path: '$.label' },
                        secondary_note: { value: null, path: '$.note' },
                    },
                }),
            },
        });
        await open(mapped());

        expect(warnings()).toHaveLength(1);
        expect(warnings()[0].dataset.kind).toBe('null_value');
        expect(continueButton().getAttribute('aria-disabled')).toBe('true');
    });

    it('refreshes the preview when the step is opened again after a mapping change', async () => {
        await open(mapped());

        const first = previews().length;

        previewReply = () => ({
            status: 200,
            json: { data: preview({}, '999') },
        });
        await click($('[data-test=back]'));
        await click($('[data-test=slots-continue]'));

        expect(previews().length).toBeGreaterThan(first);
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '999',
        );
    });

    it('blocks Continue with a reason when the preview cannot be built', async () => {
        previewReply = () => ({
            status: 422,
            json: { error: { code: 'mapping.no_plan' } },
        });
        await open(mapped());

        expect(continueButton().getAttribute('aria-disabled')).toBe('true');
        expect(text('[data-test=continue-reason]')).toContain(
            'preview could not be built',
        );
    });

    it('warns about a slot the preview reports unavailable and blocks Continue', async () => {
        previewReply = () => ({
            status: 200,
            json: {
                data: preview({
                    slots: {
                        headline_value: { value: '284680', path: '$.total' },
                        kpi_label: { value: 'Revenue', path: '$.label' },
                        secondary_note: {
                            value: null,
                            path: '$.note',
                            unavailable: 'missing',
                        },
                    },
                }),
            },
        });
        await open(mapped());

        expect(warnings()).toHaveLength(1);
        expect(warnings()[0].dataset.kind).toBe('unavailable_value');
        expect(continueButton().getAttribute('aria-disabled')).toBe('true');
    });

    it('warns about a stale mapping and blocks Continue', async () => {
        previewReply = () => ({
            status: 200,
            json: { data: preview({ plan_stale: true }) },
        });
        await open(mapped());

        expect(warnings().map((w) => w.dataset.kind)).toContain(
            'stale_mapping',
        );
        expect(continueButton().getAttribute('aria-disabled')).toBe('true');
    });

    it('disables the checkboxes when nothing can be changed', async () => {
        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);
        setConnectionForTest(false);
        await settle();

        const box = $('[data-test=warning-ack]')!;

        expect(box.hasAttribute('disabled')).toBe(true);
        await click(box);
        expect(box.getAttribute('aria-checked')).toBe('false');
        expect($('[data-test=preview-readonly]')).not.toBeNull();
    });

    it('sets the Tablet canvas to 768px', async () => {
        await open(mapped());
        await click(
            $$('[role=radio]').find(
                (r) => r.textContent?.trim() === 'Tablet',
            ) ?? null,
        );

        expect($('[data-test=preview-canvas]')!.style.width).toBe('768px');
    });

    it('settles and offers Retry when the mapping fails after the preview resolved', async () => {
        checklistNull = true;
        await open(mapped());

        expect($('[data-test=retry-preview]')).not.toBeNull();
        expect($('[data-test=preview-loading]')).toBeNull();
        expect(text('[data-test=continue-reason]')).toContain(
            'preview could not be built',
        );
    });
});

describe('Step 5: Validate in the wizard', () => {
    it("saves a dirty Draft first, then sends the page's lock claim and the dates, and never the data source", async () => {
        stepNow = 5;
        sourceNow = { data_source_id: 's-1', endpoint_id: 'e-1' };
        await open([headline({ state: 'confirmed' }), kpiLabel(), cleared()]);
        await click($('[data-test=warning-ack]'));
        await click($('[data-step="5"] [data-test=step-button]'));

        expect($('[data-test=validate-step]')).not.toBeNull();

        calls.length = 0;
        await click($('[data-test=validate]'));

        const methods = calls
            .filter((c) => c.url === BASE || c.url === `${BASE}/validation`)
            .map((c) => `${c.method} ${c.url.replace(BASE, '')}`);

        expect(methods.slice(0, 2)).toEqual(['PUT ', 'POST /validation']);

        const post = calls.find(
            (c) => c.url === `${BASE}/validation` && c.method === 'POST',
        )!;

        expect(post.body).toMatchObject({ lock_epoch: 1 });
        expect(post.body).toHaveProperty('range');
        expect(post.body).not.toHaveProperty('data_source_id');
        // The Operation stored no report: the step says it could not check, and shows no result.
        expect(text('[data-test=validate-problem]')).toContain(
            'could not finish',
        );
        expect($('[data-test=result-title]')).toBeNull();
    });
});

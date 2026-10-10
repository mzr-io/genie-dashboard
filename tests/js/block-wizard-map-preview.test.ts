// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import LivePreview from '../../resources/js/components/wizard/LivePreview.vue';
import {
    blockTypeFor,
    defaultDisplay,
} from '../../resources/js/lib/blockWizard';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type {
    ApiChange,
    Blocker,
    ChecklistSlot,
} from '../../resources/js/lib/blockMapping';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 3.14 (UI stage 4): the live preview from the Query Plan engine, its hotspots, the missing-slot placeholder and the source legend.
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
const row = (key: string) =>
    $$('[data-test=slot-row]').find((r) => r.dataset.slotKey === key)!;

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
    previewReply = () => ({ status: 200, json: { data: preview() } });
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

function answer(): Reply {
    revision += 1;

    return {
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
const hotspot = (key: string) =>
    $$('[data-test=preview-hotspot]').find(
        (h) =>
            h.closest<HTMLElement>('[data-slot-hotspot]')?.dataset
                .slotHotspot === key,
    )!;
const sleep = async (ms: number) => {
    if (vi.isFakeTimers()) {
        await vi.advanceTimersByTimeAsync(ms);
    } else {
        await new Promise((resolve) => setTimeout(resolve, ms));
    }

    await flushPromises();
};
const press = (target: Element, key: string) =>
    target.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );
const fieldsOffer = [
    {
        kind: 'field',
        path: '$.total',
        display: 'total',
        type: 'number',
        effective_type: 'number',
        role: 'value',
        example: '284680',
        compatible: true,
    },
    {
        kind: 'field',
        path: '$.data.revenue',
        display: 'data.revenue',
        type: 'number',
        effective_type: 'number',
        role: 'measure',
        example: '12',
        compatible: true,
    },
];

describe('Live preview: the server payload', () => {
    it('renders the engine payload on step 3, with hotspots, the legend and the note', async () => {
        roleFields = [
            {
                path: '$.total',
                display: 'total',
                type: 'number',
                example: '284680',
                role: 'value',
                confidence: 'high',
                overridden: false,
                in_records: false,
                suggestion: null,
            },
        ];
        await open(mapped());

        expect(previews()).toHaveLength(1);
        expect(previews()[0].body).toBeNull();
        expect(text('[data-test=kpi-headline]')).toBe('284,680');
        // The Draft's own header still applies, not the server's title.
        expect(text('[data-test=kpi-title]')).toBe('Revenue today');
        expect(
            text('[data-test=preview-region] [data-test=kpi-body]'),
        ).toContain('Updated daily');
        expect(hotspot('headline_value').getAttribute('aria-label')).toBe(
            'Headline value: total, 284,680. Change field',
        );
        expect(
            hotspot('headline_value')
                .closest('[data-slot-hotspot]')!
                .querySelector('[role=tooltip]')?.textContent,
        ).toBe('Click to change field · Headline value ← total');
        expect($$('[data-test=preview-hotspot]')).toHaveLength(3);

        const rows = $$('[data-test=legend-row]');

        expect(text('[data-test=source-legend] h3')).toBe(
            'Where each part comes from',
        );
        expect(rows).toHaveLength(3);
        expect(rows[0].textContent).toContain('Value');
        expect(rows[0].textContent).toContain('total');
        expect(rows[0].textContent).toContain('→');
        expect(rows[0].textContent).toContain('284,680');
        expect(text('[data-test=sample-note]')).toContain(
            'Sample data only. Published blocks call',
        );
        expect($$('[data-test=preview-sample-label]')).toHaveLength(1);
        expect(
            $('[data-test=preview-region]')?.getAttribute('aria-label'),
        ).toBe('Live preview, sample data');
        expect($('[data-test=preview-notice]')).toBeNull();
    });

    it('keeps the Loading card until the first payload arrives', async () => {
        let release: () => void = () => {};
        const gate = new Promise<void>((resolve) => (release = resolve));

        previewReply = () => ({ status: 200, json: { data: preview() } });

        const original = globalThis.fetch;

        vi.stubGlobal('fetch', async (url: string, init?: never) => {
            if (url === `${BASE}/preview`) {
                await gate;
            }

            return (original as any)(url, init);
        });
        await open(mapped());

        expect($('[data-test=kpi-loading]')).not.toBeNull();
        release();
        await settle();

        expect($('[data-test=kpi-loading]')).toBeNull();
        expect(text('[data-test=kpi-headline]')).toBe('284,680');
    });

    it('does not preview on steps 1 and 2 and keeps the Loading card there', async () => {
        current = mapped();
        window.history.replaceState(
            {},
            '',
            '/admin/blocks/create?draft=d-1&step=1',
        );
        wrapper = mount(BlockWizard as never, {
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
        await settle();

        expect(previews()).toHaveLength(0);
        expect($('[data-test=kpi-loading]')).not.toBeNull();
        expect($('[data-test=preview-hotspots]')).toBeNull();
        expect($('[data-test=skip-to-checklist]')).toBeNull();
    });
});

describe('Live preview: refresh after a mapping write', () => {
    it('refreshes once, debounced, and keeps the last good preview until the new one lands', async () => {
        await open(mapped());
        previewReply = () => ({
            status: 200,
            json: { data: preview({}, '300000') },
        });
        current = mapped();
        await click($('[data-test=rerun]'));

        // Nothing yet inside the debounce window: the last good value is still on screen.
        expect(text('[data-test=kpi-headline]')).toBe('284,680');
        await sleep(520);
        await settle();

        expect(previews()).toHaveLength(2);
        expect(text('[data-test=kpi-headline]')).toBe('300,000');
    });

    it('shows the new value, hotspot name and legend row after an override', async () => {
        offerFields();
        roleFields = [
            {
                path: '$.data.revenue',
                display: 'data.revenue',
                type: 'number',
                example: '12',
                role: 'measure',
                confidence: 'high',
                overridden: false,
                in_records: false,
                suggestion: null,
            },
        ];
        await open(mapped());
        writeReply = () => {
            current = [
                headline({
                    state: 'overridden',
                    path: '$.data.revenue',
                    display: 'data.revenue',
                    example: '12',
                }),
                kpiLabel(),
                note({ state: 'auto_high', confidence: 'high' }),
            ];
            revision += 1;
            previewReply = () => ({
                status: 200,
                json: {
                    data: preview(
                        {
                            slots: {
                                headline_value: {
                                    value: '12',
                                    path: '$.data.revenue',
                                },
                            },
                        },
                        '12',
                    ),
                },
            });

            return answer();
        };
        await click(
            row('headline_value').querySelector('[data-test=change-field]'),
        );
        const target = $$('[data-test=picker-option]').find((o) =>
            o.textContent?.includes('data.revenue'),
        )!;

        await click(target);
        await sleep(520);
        await settle();

        expect(text('[data-test=kpi-headline]')).toBe('12');
        expect(hotspot('headline_value').getAttribute('aria-label')).toBe(
            'Headline value: data.revenue, 12. Change field',
        );
        expect($$('[data-test=legend-row]')[0].textContent).toContain(
            'data.revenue',
        );
        expect($$('[data-test=legend-row]')[0].textContent).toContain('12');
    });

    it('refreshes after a Confirm too', async () => {
        await open([
            headline(),
            kpiLabel(),
            note({ state: 'auto_high', confidence: 'high' }),
        ]);
        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );
        await sleep(520);
        await settle();

        expect(previews()).toHaveLength(2);
    });
});

function offerFields(): void {
    fieldsReply = {
        status: 200,
        json: { data: { slot: 'x', fields: fieldsOffer } },
    };
}

describe('Live preview: hotspots', () => {
    it('opens the slot picker with focus in the search on click and returns focus to the hotspot on Escape', async () => {
        offerFields();
        await open(mapped());
        hotspot('headline_value').focus();
        await click(hotspot('headline_value'));

        expect($('[data-test=picker-popover]')).not.toBeNull();
        expect(document.activeElement).toBe($('[data-test=picker-search]'));

        press($('[data-test=picker-search]')!, 'Escape');
        await settle();

        expect($('[data-test=picker-popover]')).toBeNull();
        expect(document.activeElement).toBe(hotspot('headline_value'));
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    });

    it('is a button, so Enter and Space activate it natively', async () => {
        await open(mapped());

        expect(hotspot('headline_value').tagName).toBe('BUTTON');
        expect(hotspot('headline_value').getAttribute('type')).toBe('button');
    });

    it('shows a row a filter would hide', async () => {
        offerFields();
        await open(mapped());
        await click(
            $$(
                '[data-test=slot-filter] button, [data-test=slot-filter] [role=radio]',
            ).find((b) => /Missing/.test(b.textContent ?? ''))!,
        );

        expect(rowKeys()).toHaveLength(0);
        await click(hotspot('headline_value'));

        expect(rowKeys()).toContain('headline_value');
        expect($('[data-test=picker-popover]')).not.toBeNull();
    });
});

function rowKeys(): string[] {
    return $$('[data-test=slot-row]').map((r) => r.dataset.slotKey!);
}

describe('Live preview: a required headline that is not mapped', () => {
    it('shows the hatched placeholder, which opens the picker', async () => {
        offerFields();
        previewReply = () => ({
            status: 422,
            json: { error: { code: 'mapping.no_plan' } },
        });
        await open([
            headline({
                state: 'required_missing',
                path: null,
                display: null,
                example: null,
            }),
            kpiLabel(),
        ]);

        const button = $('[data-test=missing-slot-placeholder]')!;

        expect(button.tagName).toBe('BUTTON');
        expect(button.getAttribute('aria-label')).toBe(
            'Headline value, required, not mapped. Choose field',
        );
        expect(button.textContent?.replace(/\s+/g, ' ').trim()).toBe(
            'Headline value required · No Value mapped · click to choose',
        );
        expect($$('[data-test=preview-hotspot]')).toHaveLength(0);
        expect($('[data-test=preview-notice]')).toBeNull();

        await click(button);

        expect($('[data-test=picker-popover]')).not.toBeNull();
        expect(document.activeElement).toBe($('[data-test=picker-search]'));

        press($('[data-test=picker-search]')!, 'Escape');
        await settle();

        expect(document.activeElement).toBe(button);
    });
});

describe('Live preview: refusals and notices', () => {
    it('keeps the last good preview and says when to try again on a 429', async () => {
        await open(mapped());
        previewReply = () => ({
            status: 429,
            json: { error: { code: 'rate_limited', retry_after: 12 } },
        });
        await click($('[data-test=rerun]'));
        await sleep(520);
        await settle();

        expect(text('[data-test=kpi-headline]')).toBe('284,680');
        expect(text('[data-test=preview-notice]')).toContain('12 seconds');
    });

    it('names the cause of a guard refusal and renders nothing partial', async () => {
        previewReply = () => ({
            status: 422,
            json: { error: { code: 'mapping.guard_exceeded' } },
        });
        await open(mapped());

        expect(text('[data-test=preview-notice]')).toContain('safety limit');
        expect($('[data-test=kpi-headline]')).toBeNull();
        expect($('[data-test=kpi-loading]')).toBeNull();
        expect($('[data-test=kpi-unavailable]')).not.toBeNull();
    });

    it('names the setting that is not configured', async () => {
        previewReply = () => ({
            status: 422,
            json: {
                error: { code: 'mapping.setting_not_configured' },
                setting: 'DASHFLOW_PREVIEW_MAX_WALL_MS',
            },
        });
        await open(mapped());

        expect(text('[data-test=preview-notice]')).toContain(
            'DASHFLOW_PREVIEW_MAX_WALL_MS',
        );
    });

    it('says so when the preview cannot be built, and keeps the last one when a refresh fails', async () => {
        await open(mapped());
        previewReply = () => ({ status: 503, json: {} });
        await click($('[data-test=rerun]'));
        await sleep(520);
        await settle();

        expect(text('[data-test=kpi-headline]')).toBe('284,680');
        expect(text('[data-test=preview-notice]')).toContain(
            'Showing the last one',
        );
    });

    it('shows a stale-plan notice and still the preview', async () => {
        previewReply = () => ({
            status: 200,
            json: { data: preview({ plan_stale: true }) },
        });
        await open(mapped());

        expect(text('[data-test=preview-notice]')).toContain('earlier sample');
        expect(text('[data-test=kpi-headline]')).toBe('284,680');
    });

    it('renders an unavailable slot as the server says, never 0, and the legend says so', async () => {
        previewReply = () => ({
            status: 200,
            json: {
                data: preview({
                    payload: {
                        ...preview().payload,
                        state: 'unavailable',
                        headline: { available: false, field: 'Headline value' },
                    },
                    unavailable: { headline_value: 'missing' },
                    slots: {
                        headline_value: {
                            value: null,
                            path: '$.total',
                            unavailable: 'missing',
                        },
                    },
                }),
            },
        });
        await open(mapped());

        expect(text('[data-test=kpi-unavailable]')).not.toMatch(/^0$/);
        expect($('[data-test=kpi-headline]')).toBeNull();
        expect($$('[data-test=legend-row]')[0].textContent).toContain(
            'Unavailable (the field is not in the sample)',
        );
        expect($$('[data-test=legend-row]')[0].textContent).not.toMatch(
            /→\s*0\b/,
        );
    });
});

describe('Live preview: read-only and the skip link', () => {
    it('draws no buttons over the card when the lock is held elsewhere, and still shows the legend', async () => {
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
        await open(mapped());

        expect($$('[data-test=preview-hotspot]')).toHaveLength(0);
        expect($$('[data-test=preview-hotspot-static]').length).toBeGreaterThan(
            0,
        );
        expect($$('[data-test=legend-row]')).toHaveLength(3);
    });

    it('has no interactive chrome on the card', async () => {
        await open(mapped());

        expect(
            $$('[data-test=preview-canvas] button').filter(
                (b) =>
                    !b.hasAttribute('data-test') ||
                    !/preview-hotspot|missing-slot/.test(
                        b.getAttribute('data-test')!,
                    ),
            ),
        ).toHaveLength(0);
    });

    it('moves focus to the slot checklist from the skip link', async () => {
        await open(mapped());

        const link = $('[data-test=skip-to-checklist]')!;

        expect(link.textContent).toBe('Skip to slot checklist');
        expect(
            link.compareDocumentPosition($('[data-test=preview-region]')!) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        await click(link);

        expect(
            $('[data-test=slot-checklist]')!.contains(document.activeElement),
        ).toBe(true);
    });
});

describe('Preview client', () => {
    it('drops the answer of an older request that lands late', async () => {
        const { createPreviewRunner } =
            await import('../../resources/js/lib/blockPreview');
        const results: string[] = [];
        const resolvers: ((v: any) => void)[] = [];
        const runner = createPreviewRunner({
            delay: 5,
            load: () => new Promise((resolve) => resolvers.push(resolve)),
            onResult: (r) =>
                results.push(r.sample_revision as unknown as string),
            onProblem: () => results.push('problem'),
        });

        runner.request(true);
        runner.request(true);
        resolvers[1]({
            payload: {},
            unavailable: {},
            slots: {},
            sample_revision: 2,
            draft_revision: 1,
            plan_stale: false,
        });
        resolvers[0]({
            payload: {},
            unavailable: {},
            slots: {},
            sample_revision: 1,
            draft_revision: 1,
            plan_stale: false,
        });
        await sleep(0);

        expect(results).toEqual([2]);
    });

    it('collapses a burst of requests into one call', async () => {
        const { createPreviewRunner } =
            await import('../../resources/js/lib/blockPreview');
        let loads = 0;
        const runner = createPreviewRunner({
            delay: 10,
            load: async () => {
                loads += 1;

                return {
                    payload: {} as never,
                    unavailable: {},
                    slots: {},
                    sample_revision: 1,
                    draft_revision: 1,
                    plan_stale: false,
                };
            },
            onResult: () => {},
            onProblem: () => {},
        });

        runner.request();
        runner.request();
        runner.request();
        await sleep(40);

        expect(loads).toBe(1);
    });
});

function mountPreview(props: Record<string, unknown> = {}) {
    wrapper = mount(LivePreview as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
        props: {
            name: 'Revenue today',
            description: '',
            display: defaultDisplay(blockTypeFor('kpi-card')!),
            version: '1.0',
            live: true,
            draftId: 'd-1',
            ...props,
        },
    });

    return wrapper;
}

const allPreviews = () => calls.filter((c) => c.url.endsWith('/preview'));

describe('Live preview: requests', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    it('sends the preview as a background request with no body', async () => {
        await open(mapped());

        expect(previews()[0].headers['X-Background']).toBe('1');
        expect(previews()[0].body).toBeNull();
    });

    it('re-requests on a sample revision change, but not on the first read of it', async () => {
        const view = mountPreview({ sampleRevision: null });

        await sleep(10);
        expect(allPreviews()).toHaveLength(1);

        await view.setProps({ sampleRevision: 1 });
        await sleep(500);
        expect(allPreviews()).toHaveLength(1);

        await view.setProps({ sampleRevision: 2 });
        await sleep(500);
        expect(allPreviews()).toHaveLength(2);
    });

    it('re-requests when the Draft changes', async () => {
        const view = mountPreview();

        await sleep(10);
        await view.setProps({ draftId: 'd-2' });
        await sleep(10);

        expect(allPreviews().map((c) => c.url)).toEqual([
            `${BASE}/preview`,
            '/api/v1/admin/blocks/drafts/d-2/preview',
        ]);
    });

    it('clears a previous result on no_plan and no_sample, and shows Unavailable, not Loading', async () => {
        for (const code of ['mapping.no_plan', 'mapping.no_sample']) {
            previewReply = () => ({ status: 200, json: { data: preview() } });

            const view = mountPreview();

            await sleep(10);
            expect(text('[data-test=kpi-headline]')).toBe('284,680');

            previewReply = () => ({ status: 422, json: { error: { code } } });
            await view.setProps({ refreshKey: 1 });
            await sleep(500);

            expect($('[data-test=kpi-headline]')).toBeNull();
            expect($('[data-test=kpi-loading]')).toBeNull();
            expect($('[data-test=kpi-unavailable]')).not.toBeNull();
            view.unmount();
        }
    });

    it('drops a pending call and makes no more calls when the step is left or the pane unmounts', async () => {
        const view = mountPreview();

        await sleep(10);
        await view.setProps({ refreshKey: 1 });
        await view.setProps({ live: false });
        await sleep(1000);
        expect(allPreviews()).toHaveLength(1);

        await view.setProps({ live: true });
        await sleep(10);
        expect(allPreviews()).toHaveLength(2);
        await view.setProps({ refreshKey: 2 });
        view.unmount();
        wrapper = null;
        await sleep(1000);
        expect(allPreviews()).toHaveLength(2);
    });

    it('retries a 429 once after retry_after seconds, and only once', async () => {
        previewReply = () => ({
            status: 429,
            json: { error: { code: 'rate_limited', retry_after: 3 } },
        });
        mountPreview();
        await sleep(10);

        expect(allPreviews()).toHaveLength(1);
        await sleep(2900);
        expect(allPreviews()).toHaveLength(1);
        await sleep(200);
        expect(allPreviews()).toHaveLength(2);
        await sleep(10000);
        expect(allPreviews()).toHaveLength(2);
    });

    it('passes a lost session to the page and treats a malformed body as a failure', async () => {
        previewReply = () => ({ status: 401, json: { error: { code: 'x' } } });

        const view = mountPreview();

        await sleep(10);
        expect(view.emitted('auth-error')).toHaveLength(1);
        view.unmount();

        previewReply = () => ({ status: 200, json: { data: { payload: {} } } });
        mountPreview();
        await sleep(10);

        expect($('[data-test=kpi-headline]')).toBeNull();
        expect(text('[data-test=preview-notice]')).toContain("couldn't");
    });
});

describe('Live preview: hotspot geometry and pending refresh', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    const rects: Record<
        string,
        { left: number; top: number; width: number; height: number }
    > = {
        'preview-canvas': { left: 100, top: 50, width: 400, height: 300 },
        'kpi-headline': { left: 120, top: 90, width: 200, height: 30 },
    };

    beforeEach(() => {
        vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
            function (this: Element) {
                const r = rects[this.getAttribute('data-test') ?? ''] ?? {
                    left: 0,
                    top: 0,
                    width: 0,
                    height: 0,
                };

                return {
                    ...r,
                    x: r.left,
                    y: r.top,
                    right: r.left + r.width,
                    bottom: r.top + r.height,
                    toJSON: () => ({}),
                } as DOMRect;
            },
        );
    });

    const mappedSlots = (path: string) => [
        headline({ state: 'confirmed', path }),
    ];

    it('positions a hotspot relative to the canvas', async () => {
        previewReply = () => ({ status: 200, json: { data: preview() } });
        mountPreview({ slots: mappedSlots('$.total') });
        await sleep(10);

        const style = hotspot('headline_value').closest<HTMLElement>(
            '[data-slot-hotspot]',
        )!.style;

        expect([style.left, style.top, style.width, style.height]).toEqual([
            '20px',
            '40px',
            '200px',
            '30px',
        ]);
    });

    it('does not offer a hotspot whose field is newer than the preview', async () => {
        previewReply = () => ({ status: 200, json: { data: preview() } });
        mountPreview({ slots: mappedSlots('$.other') });
        await sleep(10);

        expect($$('[data-test=preview-hotspot]')).toHaveLength(0);
        expect($$('[data-test=preview-hotspot-static]')).toHaveLength(1);
    });

    it('dismisses the tooltip with Escape and shows the read-only label when blocked', async () => {
        previewReply = () => ({ status: 200, json: { data: preview() } });

        const view = mountPreview({ slots: mappedSlots('$.total') });

        await sleep(10);
        hotspot('headline_value').dispatchEvent(
            new FocusEvent('focusin', { bubbles: true }),
        );
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await settle();

        expect($('[data-test=hotspot-tip]')!.className).toContain('!hidden');

        await view.setProps({ blocked: 'Read only' });
        expect(text('[data-test=preview-readonly]')).toBe(
            'Read-only: fields cannot be changed here.',
        );
    });
});

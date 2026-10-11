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
import { blockPresentationLabels as labels } from '../../resources/js/locales/labels';

// Story 3.15b: the presentation rules editor of wizard step 3 (format, emphasis, direction, bands, labels, empty display).
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

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const row = (key: string) =>
    $$('[data-test=slot-row]').find((r) => r.dataset.slotKey === key)!;
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
                                                  needs_choice: false,
                                                  candidates: [],
                                                  fields: roleFields,
                                              }
                                            : null,
                                    slot_map: {},
                                    presentation: stored,
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

const presentationPuts = () =>
    calls.filter(
        (c) => c.method === 'PUT' && c.url === `${BASE}/mapping/presentation`,
    );
const toggle = (key: string) =>
    row(key).querySelector<HTMLElement>('[data-test=presentation-toggle]')!;
const editor = (key: string) =>
    row(key).querySelector<HTMLElement>('[data-test=presentation-editor]')!;
const control = (key: string, test: string) =>
    editor(key).querySelector<HTMLElement>(`[data-test=${test}]`)!;

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

describe('Presentation editor: opening', () => {
    it('expands one row at a time with Format, Emphasis and Direction in columns that stack under 640px', async () => {
        await open(mapped());

        expect(editor('headline_value')).toBeNull();
        await click(toggle('headline_value'));

        expect(toggle('headline_value').getAttribute('aria-expanded')).toBe(
            'true',
        );
        expect($$('[data-test=presentation-columns] > section')).toHaveLength(
            3,
        );
        // Three columns from 640px up (`sm:`), one column below.
        const columns = $('[data-test=presentation-columns]')!;

        expect(columns.className).toContain('sm:grid-cols-3');
        expect(columns.className).not.toMatch(/(^|\s)grid-cols-3/);

        await click(toggle('comparison'));

        expect($$('[data-test=presentation-editor]')).toHaveLength(1);
        expect(editor('comparison')).toBeDefined();
        expect(toggle('headline_value').getAttribute('aria-expanded')).toBe(
            'false',
        );
    });
});

describe('Presentation editor: format', () => {
    it('shows the example through the Renderer descriptor path and stores the descriptor only', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-kind'), 'currency');

        // A currency with no code has no example yet, and nothing is sent.
        expect(text('[data-test=format-example]')).toBe(labels.exampleNone);
        expect(presentationPuts()).toHaveLength(0);
        expect(text('[data-test=slots-continue-reason]')).toContain(
            labels.blockedContinue,
        );

        await setValue(control('headline_value', 'format-currency'), 'usd');

        // The Renderer's own descriptor path: Intl keeps the currency's two places until places are set.
        expect(text('[data-test=format-example]')).toBe(
            labels.exampleValue('$284,680.00'),
        );

        await setValue(control('headline_value', 'format-places'), '0');

        expect(text('[data-test=format-example]')).toBe(
            labels.exampleValue('$284,680'),
        );
        expect(presentationPuts()).toHaveLength(2);
        expect(presentationPuts()[1].body).toMatchObject({
            presentation: {
                slots: {
                    headline_value: {
                        format: {
                            kind: 'currency',
                            currencyCode: 'USD',
                            decimalPlaces: 0,
                        },
                    },
                },
            },
        });
        // Descriptors only: no formatted string anywhere in the stored document.
        expect(
            JSON.stringify(presentationPuts()[1].body!.presentation),
        ).not.toContain('284');
        expect(text('[data-test=slots-continue-reason]')).not.toContain(
            labels.blockedContinue,
        );
    });

    it('integer is a number with zero places; thousands separator and units suffix are descriptors', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-kind'), 'integer');
        await setValue(control('headline_value', 'format-units'), 'kg');

        expect(presentationPuts().at(-1)!.body!.presentation).toMatchObject({
            slots: {
                headline_value: {
                    format: {
                        kind: 'number',
                        decimalPlaces: 0,
                        unitsSuffix: 'kg',
                    },
                },
            },
        });
        expect(text('[data-test=format-example]')).toContain('kg');
    });

    it('does not send a write that changes nothing', async () => {
        stored = {
            slots: { headline_value: { format: { kind: 'percent' } } },
        };
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-kind'), 'percent');

        expect(presentationPuts()).toHaveLength(0);
    });
});

describe('Presentation editor: preview', () => {
    it('refreshes the preview after a write and not after a refused value', async () => {
        const previews = () =>
            calls.filter((c) => c.url === `${BASE}/preview`).length;

        await open(mapped());
        await click(toggle('headline_value'));
        await new Promise((resolve) => setTimeout(resolve, 600));

        const before = previews();

        await setValue(control('headline_value', 'format-places'), '-1');
        await new Promise((resolve) => setTimeout(resolve, 600));

        expect(previews()).toBe(before);

        await setValue(control('headline_value', 'format-places'), '1');
        await new Promise((resolve) => setTimeout(resolve, 600));

        expect(previews()).toBeGreaterThan(before);
    });
});

describe('Presentation editor: invalid values', () => {
    it('refuses negative places inline, blocks Continue and sends nothing', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-places'), '-1');

        expect(text('[data-test=places-hint]')).toBe(labels.placesRange(20));
        expect(presentationPuts()).toHaveLength(0);
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=presentation-errors]')).toContain(
            labels.placesRange(20),
        );

        // A valid value clears it and saves.
        await setValue(control('headline_value', 'format-places'), '2');

        expect($('[data-test=presentation-errors]')).toBeNull();
        expect(presentationPuts()).toHaveLength(1);
    });

    it('refuses a bad currency code', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-kind'), 'currency');
        await setValue(control('headline_value', 'format-currency'), 'xx1');

        expect(editor('headline_value').textContent).toContain(
            labels.currencyShape,
        );
        expect(presentationPuts()).toHaveLength(0);
    });

    it('shows the server reason when a second headline is refused', async () => {
        presentationReply = () => ({
            status: 422,
            json: {
                error: { code: 'blocks.invalid_config' },
                errors: {
                    'config.presentation.slots.comparison.emphasis': [
                        'Only one slot can be the headline: headline_value already is.',
                    ],
                },
            },
        });
        stored = {
            slots: { headline_value: { emphasis: { role: 'headline' } } },
        };
        await open(mapped());
        await click(toggle('comparison'));
        await setValue(control('comparison', 'emphasis-role'), 'headline');

        expect(presentationPuts()).toHaveLength(1);
        expect(text('[data-test=emphasis-error]')).toBe(
            'Only one slot can be the headline: headline_value already is.',
        );
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
    });
});

describe('Presentation editor: emphasis and direction', () => {
    it('writes role, size and weight', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'emphasis-role'), 'headline');
        await setValue(control('headline_value', 'emphasis-size'), 'lg');
        await setValue(control('headline_value', 'emphasis-weight'), 'bold');

        expect(presentationPuts().at(-1)!.body!.presentation).toMatchObject({
            slots: {
                headline_value: {
                    emphasis: { role: 'headline', size: 'lg', weight: 'bold' },
                },
            },
        });
    });

    it('direction applies to the comparison slot only and lower is better adds better and worse', async () => {
        await open(mapped());
        await click(toggle('headline_value'));

        expect($('[data-test=direction]')).toBeNull();
        expect(text('[data-test=direction-na]')).toBe(labels.directionNotHere);

        await click(toggle('comparison'));

        expect(text('[data-test=direction-note]')).toBe(labels.directionNote);
        expect(text('[data-test=direction-note]')).toContain('up = green');

        await setValue(control('comparison', 'direction'), 'lower_is_better');

        expect(presentationPuts().at(-1)!.body!.presentation).toMatchObject({
            slots: { comparison: { direction: 'lower_is_better' } },
        });
        expect(text('[data-test=direction-note]')).toContain('better');
        expect(text('[data-test=direction-note]')).toContain('worse');
    });
});

describe('Presentation editor: bands', () => {
    const addBand = () => click($('[data-test=band-add]'));
    const band = (n: number) => $$('[data-test=band-row]')[n];
    const within = (n: number, test: string) =>
        band(n).querySelector<HTMLElement>(`[data-test=${test}]`);

    it('blocks a band without an icon or a label, client-side and without a write', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await addBand();

        expect($$('[data-test=band-row]')).toHaveLength(1);
        expect(text('[data-test=band-errors]')).toContain(
            labels.bandIconNeeded,
        );
        expect(text('[data-test=band-errors]')).toContain(
            labels.bandLabelNeeded,
        );
        expect(presentationPuts()).toHaveLength(0);
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('saves a complete band with colour, icon and label; the accent colour is not offered', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await addBand();
        await setValue(within(0, 'band-value'), '100');
        await setValue(within(0, 'band-icon'), 'trending-up');
        await setValue(within(0, 'band-label'), 'On target');

        expect(presentationPuts()).toHaveLength(1);
        expect(presentationPuts()[0].body!.presentation).toEqual({
            bands: [
                {
                    op: '>=',
                    value: '100',
                    color: 'success',
                    icon: 'trending-up',
                    label: 'On target',
                },
            ],
        });
        expect(
            Array.from(within(0, 'band-color')!.querySelectorAll('option')).map(
                (o) => o.textContent?.trim(),
            ),
        ).toEqual(['Success', 'Warning', 'Error', 'Info', 'Neutral']);
    });

    it('between shows a second value; removing the first band renumbers the rest', async () => {
        stored = {
            bands: [
                {
                    op: '>=',
                    value: '100',
                    color: 'success',
                    icon: 'star',
                    label: 'High',
                },
                {
                    op: 'between',
                    value: '50',
                    to: '99',
                    color: 'warning',
                    icon: 'flag',
                    label: 'Mid',
                },
                {
                    op: '<=',
                    value: '49',
                    color: 'error',
                    icon: 'bell',
                    label: 'Low',
                },
            ],
        };
        await open(mapped());
        await click(toggle('headline_value'));

        expect(
            $$('[data-test=band-order]').map((n) => n.textContent?.trim()),
        ).toEqual(['1', '2', '3']);
        expect(within(0, 'band-to')).toBeNull();
        expect(within(1, 'band-to')).not.toBeNull();

        await click(band(0).querySelector('[data-test=band-remove]'));

        expect(presentationPuts().at(-1)!.body!.presentation.bands).toEqual([
            expect.objectContaining({ label: 'Mid' }),
            expect.objectContaining({ label: 'Low' }),
        ]);
        expect(
            $$('[data-test=band-order]').map((n) => n.textContent?.trim()),
        ).toEqual(['1', '2']);
        expect((within(0, 'band-label') as HTMLInputElement).value).toBe('Mid');
    });

    it('stacks each band row under 640px', async () => {
        stored = {
            bands: [
                {
                    op: '>=',
                    value: '1',
                    color: 'info',
                    icon: 'star',
                    label: 'A',
                },
            ],
        };
        await open(mapped());
        await click(toggle('headline_value'));

        expect(band(0).className).toContain('sm:grid-cols-');
        expect(band(0).className).not.toMatch(/(^|\s)grid-cols-\[/);
    });
});

describe('Presentation editor: faults on a collapsed row', () => {
    async function collapsedFaultyBand(): Promise<void> {
        await open(mapped());
        await click(toggle('headline_value'));
        await click($('[data-test=band-add]'));
        await click(toggle('headline_value'));
    }

    it('shows the first fault on the collapsed row', async () => {
        await collapsedFaultyBand();

        expect(editor('headline_value')).toBeNull();
        expect(
            row('headline_value').querySelector(
                '[data-test=presentation-fault]',
            ),
        ).not.toBeNull();
    });

    it('opens the faulty row and focuses inside its editor when Continue is activated', async () => {
        await collapsedFaultyBand();
        await click($('[data-test=slots-continue]'));
        await settle();

        expect(editor('headline_value')).not.toBeNull();
        expect(editor('headline_value').contains(document.activeElement)).toBe(
            true,
        );
    });
});

describe('Presentation editor: labels and empty display', () => {
    it('writes label text, prefix, suffix and the empty display (not for the headline)', async () => {
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'label-prefix'), '~');
        await setValue(control('headline_value', 'label-suffix'), ' today');

        expect($('[data-test=empty-display]')).toBeNull();
        expect(text('[data-test=format-example]')).toContain('~');

        await click(toggle('comparison'));
        await setValue(control('comparison', 'empty-display'), '—');

        expect(presentationPuts().at(-1)!.body!.presentation).toMatchObject({
            slots: {
                headline_value: { label: { prefix: '~', suffix: ' today' } },
            },
            empty_display: '—',
        });
    });
});

describe('Presentation editor: conflicts and read-only', () => {
    it('hands a 409 to the page and loses nothing the Admin typed', async () => {
        presentationReply = () => ({
            status: 409,
            json: { error: { code: 'blocks.revision_conflict' } },
        });
        await open(mapped());
        await click(toggle('headline_value'));
        await setValue(control('headline_value', 'format-units'), 'kg');

        expect(presentationPuts()).toHaveLength(1);
        expect(
            (control('headline_value', 'format-units') as HTMLInputElement)
                .value,
        ).toBe('kg');
    });

    it('is shown read-only without controls that change anything', async () => {
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
        stored = {
            bands: [
                {
                    op: '>=',
                    value: '1',
                    color: 'info',
                    icon: 'star',
                    label: 'A',
                },
            ],
        };
        await open(mapped());
        await click(toggle('headline_value'));

        expect(editor('headline_value').hasAttribute('disabled')).toBe(true);
        expect(text('[data-test=band-label]')).toBe('');
        expect(
            (control('headline_value', 'band-label') as HTMLInputElement).value,
        ).toBe('A');
        expect($('[data-test=band-add]')).toBeNull();
        expect($('[data-test=band-remove]')).toBeNull();
    });
});

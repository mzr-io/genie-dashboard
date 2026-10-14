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
    PickerField,
} from '../../resources/js/lib/blockMapping';
import SlotRow from '../../resources/js/components/wizard/SlotRow.vue';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    blockMapLabels as labels,
    draftLockLabels,
} from '../../resources/js/locales/labels';

// Story 3.13b (UI stage 3): the field picker, override with Undo, Treat as and the API-change messages of wizard step 3.
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

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const row = (key: string) =>
    $$('[data-test=slot-row]').find((r) => r.dataset.slotKey === key)!;
const polite = () =>
    document.querySelector('[data-announcer=polite]')?.textContent ?? '';
const writes = (tail: string) =>
    calls.filter(
        (c) => c.method === 'POST' && c.url === `${BASE}/mapping/${tail}`,
    );

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

const CURRENCY_REASON =
    "Text field can't be a numeric headline. Use it as the currency format below.";

const headlineFields = () => [
    field({
        display: 'currency',
        type: 'text',
        effective_type: 'text',
        role: 'label',
        example: 'USD',
        compatible: false,
        reason: CURRENCY_REASON,
    }),
    field({ display: 'total', example: '284680' }),
    field({ display: 'data.revenue', role: 'filter', example: '12' }),
    {
        kind: 'static_text',
        path: null,
        display: 'Static text',
        compatible: true,
    },
];

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
const opener = (key: string) =>
    row(key).querySelector<HTMLElement>('[data-test=change-field]')!;
const options = () => $$('[data-test=picker-option]');
const search = () => $('[data-test=picker-search]') as HTMLInputElement;

function press(target: Element, key: string): void {
    target.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );
}

async function type(value: string): Promise<void> {
    search().value = value;
    search().dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

async function openPicker(key = 'headline_value'): Promise<void> {
    await click(opener(key));
}

describe('Field picker: opening and listing', () => {
    it('opens a 320px popover with search focused, compatible fields first and the count announced', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        await openPicker();

        expect($('[data-test=picker-popover]')?.className).toContain('w-80');
        expect(document.activeElement).toBe(search());
        expect(opener('headline_value').getAttribute('aria-expanded')).toBe(
            'true',
        );
        expect(options().map((o) => o.dataset.path)).toEqual([
            '$.total',
            '$.data.revenue',
            '$.currency',
        ]);
        expect(polite()).toBe(labels.fieldsMatch(3));
        // The current field is the active option, and the chosen one.
        expect(options()[0].dataset.active).toBe('true');
        expect(options()[0].getAttribute('aria-selected')).toBe('true');
        // Indent guides come from the depth; the type shows as text; the example is mono.
        expect(
            options()[1].querySelectorAll('[data-test=indent-guides] > span'),
        ).toHaveLength(1);
        expect(
            options()[0].querySelector('[data-test=picker-type]')?.textContent,
        ).toBe('number');
        expect(
            options()[0].querySelector('[data-test=picker-example]')?.className,
        ).toContain('font-mono');
        // Static text is not offered (the backend cannot store it).
        expect(document.body.textContent).not.toContain('Static text');
    });

    it('is on every mappable row', async () => {
        await open([headline(), title(), note()]);

        expect($$('[data-test=change-field]')).toHaveLength(3);
    });

    it('narrows the list as you type and says how many match', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        await openPicker();
        await type('rev');
        await new Promise((resolve) => setTimeout(resolve, 600));

        expect(options().map((o) => o.dataset.path)).toEqual([
            '$.data.revenue',
        ]);
        expect(polite()).toBe(labels.fieldsMatch(1));

        await type('zzz');

        expect($('[data-test=picker-empty]')).not.toBeNull();
    });

    it('shows a loading state, then a failure with Try again inside the popover', async () => {
        fieldsReply = { status: 500, json: {} };
        await open([headline({ state: 'confirmed' })]);
        await openPicker();

        expect($('[data-test=picker-failed]')).not.toBeNull();

        offer(headlineFields());
        await click($('[data-test=picker-retry]'));

        expect(options()).toHaveLength(3);
    });
});

describe('Field picker: Text Template and Calculated Field', () => {
    const entry = (kind: 'template' | 'calculated', display: string) =>
        ({
            kind,
            path: null,
            display,
            example: 'an example',
            compatible: true,
        }) as PickerField;
    const option = (display: string) =>
        options().find((o) => o.textContent?.includes(display));

    it('offers Text Template on a text slot, without Static text, and opens the template for that slot', async () => {
        offer([
            field({ display: 'name', type: 'text', effective_type: 'text' }),
            {
                kind: 'static_text',
                path: null,
                display: 'Static text',
                compatible: true,
            },
            entry('template', 'Text Template'),
        ]);
        await open([title({ state: 'confirmed' })]);
        await openPicker('title');

        expect(document.body.textContent).not.toContain('Static text');
        expect(
            option('Text Template')?.querySelector('[data-test=picker-type]'),
        ).toBeNull();

        await click(option('Text Template')!);

        const input = $('[data-test=template-input][data-slot=title]');

        expect(input).not.toBeNull();
        expect(document.activeElement).toBe(input);
        expect(putCalls('slots/title')).toHaveLength(0);
    });

    it('offers Calculated Field on a number slot and opens an expression for it', async () => {
        offer([
            field({ display: 'total' }),
            entry('calculated', 'Calculated Field'),
        ]);
        await open([headline({ state: 'confirmed' })]);
        await openPicker();
        await click(option('Calculated Field')!);

        expect($('[data-test=calc-expression]')).not.toBeNull();
        expect(putCalls('slots/headline_value')).toHaveLength(0);
    });
});

describe('Field picker: keyboard', () => {
    it('moves with the arrows, Home and End, and Enter picks the active field', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        writeReply = () => answer();
        await openPicker();

        press(search(), 'ArrowDown');
        await settle();

        expect(options()[1].dataset.active).toBe('true');
        expect(search().getAttribute('aria-activedescendant')).toBe(
            options()[1].id,
        );

        press(search(), 'End');
        await settle();

        expect(options()[2].dataset.active).toBe('true');

        press(search(), 'Home');
        await settle();
        press(search(), 'ArrowDown');
        await settle();
        press(search(), 'Enter');
        await settle();

        expect(putCalls('slots/headline_value')).toHaveLength(1);
        expect(putCalls('slots/headline_value')[0].body).toMatchObject({
            path: '$.data.revenue',
            draft_revision: 1,
        });
        expect($('[data-test=picker-popover]')).toBeNull();
        expect(document.activeElement).toBe(row('headline_value'));
    });

    it('closes on Escape and returns focus to the opener, sending nothing', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        await openPicker();
        press(search(), 'Escape');
        await settle();

        expect($('[data-test=picker-popover]')).toBeNull();
        expect(document.activeElement).toBe(opener('headline_value'));
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    });
});

describe('Leave empty', () => {
    const leaveEmpty = (key: string) =>
        row(key).querySelector<HTMLElement>('[data-test=leave-empty]');

    it('clears an optional slot with a null path, says so, and hides itself once the slot is empty', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' }), note()]);
        writeReply = () => {
            current = [
                headline({ state: 'confirmed' }),
                note({
                    state: 'optional_empty',
                    path: null,
                    display: null,
                    reason: 'Cleared by an admin.',
                }),
            ];

            return answer({
                undo: {
                    slot: 'secondary_note',
                    slot_entry: null,
                    role: null,
                    applied: { path: null, state: 'overridden' },
                },
            });
        };

        expect(leaveEmpty('secondary_note')?.getAttribute('aria-label')).toBe(
            labels.leaveEmptyFor('Secondary note'),
        );

        await click(leaveEmpty('secondary_note'));

        const put = putCalls('slots/secondary_note')[0];

        expect(put.body).toMatchObject({ path: null, draft_revision: 1 });
        expect(put.body).toHaveProperty('lock_epoch');
        expect(row('secondary_note').dataset.state).toBe('optional_empty');
        expect(leaveEmpty('secondary_note')).toBeNull();
        expect(text('[data-test=override-note]')).toContain(
            labels.overriddenPlain(null, 'Secondary note'),
        );
    });

    it('clears an optional Type-mismatch slot', async () => {
        await open([
            title({
                state: 'type_mismatch',
                path: null,
                display: null,
                reason: 'Only found inside the rows; one value is needed here.',
            }),
        ]);

        await click(leaveEmpty('title'));

        expect(putCalls('slots/title')[0].body).toMatchObject({ path: null });
    });

    it('is not offered on a required slot', async () => {
        await open([headline({ state: 'confirmed' })]);

        expect(leaveEmpty('headline_value')).toBeNull();
    });
});

describe('Field picker: pick and Undo', () => {
    const undoPayload = {
        slot: 'headline_value',
        slot_entry: { path: '$.total', state: 'confirmed' },
        role: { path: '$.data.revenue', previous: null },
        applied: { path: '$.data.revenue', state: 'overridden' },
    };

    async function pickRevenue(): Promise<void> {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        writeReply = () => {
            current = [
                headline({
                    state: 'overridden',
                    path: '$.data.revenue',
                    display: 'data.revenue',
                    example: '12',
                }),
            ];

            return answer({
                role_change: {
                    path: '$.data.revenue',
                    from: 'filter',
                    to: 'measure',
                },
                undo: undoPayload,
            });
        };
        await openPicker();
        await click(options()[1]);
    }

    it('overrides with the lock claim and revision, shows Overridden with the role-change note and Undo, and focuses the row', async () => {
        await pickRevenue();

        const put = putCalls('slots/headline_value')[0];

        expect(put.body).toMatchObject({
            path: '$.data.revenue',
            draft_revision: 1,
        });
        expect(put.body).toHaveProperty('lock_epoch');
        expect(row('headline_value').dataset.state).toBe('overridden');
        expect(text('[data-test=override-note]')).toBe(
            `${labels.overriddenNote('data.revenue', 'Measure', 'Filter/Category')}${labels.undo}`,
        );
        expect(polite()).toContain('data.revenue is now a Measure');
        expect(document.activeElement).toBe(row('headline_value'));
        expect(text('[data-test=status-summary]')).toContain('1 confirmed');
    });

    it('does not offer a role-change sentence when no role changed', async () => {
        offer(headlineFields());
        await open([title()]);
        writeReply = () => {
            current = [title({ state: 'overridden' })];

            return answer({ undo: { ...undoPayload, slot: 'title' } });
        };
        await openPicker('title');
        await click(options()[0]);

        expect(text('[data-test=override-note]')).toContain(
            labels.overriddenPlain('name', 'Title'),
        );
    });

    it('undoes with the returned payload, restores the row and announces', async () => {
        await pickRevenue();
        writeReply = () => {
            current = [headline({ state: 'confirmed' })];

            return answer();
        };
        await click($('[data-test=undo-override]'));

        const undo = writes('undo')[0];

        expect(undo.body).toMatchObject({
            undo: undoPayload,
            draft_revision: 2,
        });
        expect(row('headline_value').dataset.state).toBe('confirmed');
        expect($('[data-test=override-note]')).toBeNull();
        expect(polite()).toBe(labels.undoneSlot('Headline value'));
        expect(document.activeElement).toBe(row('headline_value'));
    });

    it('drops Undo after another write to the slot', async () => {
        await pickRevenue();
        writeReply = () => answer();
        await openPicker();
        await click(options()[0]);

        expect(putCalls('slots/headline_value')).toHaveLength(2);
        expect($('[data-test=override-note]')).toBeNull();
    });

    it('says so, and refreshes, when the slot changed after the override (409 slot_changed)', async () => {
        await pickRevenue();
        writeReply = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.slot_changed' },
                current: { data: { ...draft(), revision: 7 } },
            },
        });
        const before = reads().length;

        await click($('[data-test=undo-override]'));

        expect(text('[data-test=map-not-saved]')).toBe(labels.undoStale);
        expect($('[data-test=override-note]')).toBeNull();
        // It is not the revision chooser: the page stays on step 3.
        expect($('[data-test=map-data-step]')).not.toBeNull();
        expect(reads().length).toBeGreaterThan(before);
    });
});

describe('Field picker: incompatible fields', () => {
    it('marks them aria-disabled with the reason, and sends nothing on Enter or click', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        await openPicker();

        const disabled = options()[2];
        const reason = document.getElementById(
            disabled.getAttribute('aria-describedby') ?? 'none',
        );

        expect(disabled.getAttribute('aria-disabled')).toBe('true');
        expect(reason?.textContent).toBe(CURRENCY_REASON);

        press(search(), 'End');
        await settle();
        press(search(), 'Enter');
        await settle();
        await click(disabled);

        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
        expect($('[data-test=picker-popover]')).not.toBeNull();
    });

    it('shows the server reason when a pick is refused (422), and keeps the row', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        writeReply = () => ({
            status: 422,
            json: {
                error: { code: 'validation_failed' },
                errors: { 'slot.headline_value': [CURRENCY_REASON] },
            },
        });
        await openPicker();
        await click(options()[0]);
        await openPicker();
        await click(options()[1]);

        expect(text('[data-test=map-not-saved]')).toBe(CURRENCY_REASON);
        expect(row('headline_value').dataset.state).toBe('confirmed');
    });
});

describe('Type mismatch', () => {
    const mismatch = () =>
        headline({
            state: 'type_mismatch',
            confidence: null,
            path: '$.data.total',
            display: 'data.total',
            example: '284680',
            reason: 'data.total is text ("284680"). Headline value needs a number.',
        });

    it('shows the pill, the reason, the fitting fields and Treat as', async () => {
        offer([
            field({ display: 'revenue' }),
            field({ display: 'count' }),
            field({ display: 'name', compatible: false, reason: 'No.' }),
        ]);
        await open([mismatch()]);

        const r = row('headline_value');

        expect(r.querySelector('.slot-pill-error')?.textContent).toBe(
            'Type mismatch',
        );
        expect(
            r.querySelector('[data-test=slot-reason]')?.textContent?.trim(),
        ).toContain('needs a number');
        expect(
            Array.from(r.querySelectorAll('[data-test=fitting-field]')).map(
                (b) => b.textContent?.trim(),
            ),
        ).toEqual(['Use revenue', 'Use count']);
        expect(r.querySelector('[data-test=treat-as-button]')).not.toBeNull();
        // Continue stays blocked by the blockers.
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('picks a fitting field directly', async () => {
        offer([field({ display: 'revenue' })]);
        await open([mismatch()]);
        writeReply = () => {
            current = [headline({ state: 'overridden' })];

            return answer();
        };
        await click(
            row('headline_value').querySelector('[data-test=fitting-field]'),
        );

        expect(putCalls('slots/headline_value')[0].body).toMatchObject({
            path: '$.revenue',
        });
    });

    it('treats the field as a number, then re-reads', async () => {
        offer([]);
        await open([mismatch()]);
        writeReply = () => {
            current = [headline({ state: 'auto_high', confidence: 'high' })];

            return answer();
        };

        const before = reads().length;

        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );

        const items = $$('[data-test=treat-as-menu] [role=menuitemradio]');

        expect(items.map((i) => i.textContent?.trim())).toEqual([
            'Text',
            'Number',
            'True or false',
            'Date',
            'Automatic',
        ]);

        await click(items[1]);

        expect(putCalls('field-types')[0].body).toMatchObject({
            path: '$.data.total',
            type: 'number',
            draft_revision: 1,
        });
        expect(reads().length).toBeGreaterThan(before);
        expect(row('headline_value').dataset.state).toBe('auto_high');
        expect(polite()).toBe(labels.treatedAs('total', 'Number'));
    });

    it('says the change was not saved when Treat as fails', async () => {
        offer([]);
        await open([mismatch()]);
        writeReply = () => ({ status: 500, json: {} });
        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );
        await click($('[data-test=treat-as-menu] [role=menuitemradio]'));

        expect($('[data-test=map-not-saved]')).not.toBeNull();
        expect(row('headline_value').dataset.state).toBe('type_mismatch');
    });
});

describe('API change', () => {
    const gone = () =>
        headline({
            state: 'required_missing',
            confidence: null,
            path: null,
            display: null,
            example: null,
        });

    it('shows the catalogue message and a Use button that overrides to the match, never silently', async () => {
        apiChanged = [
            {
                slot: 'headline_value',
                path: '$.total',
                display: 'total',
                state: 'required_missing',
                match: { path: '$.grand_total', display: 'grand_total' },
            },
        ];
        offer([]);
        await open([gone()]);

        const r = row('headline_value');

        expect(
            r
                .querySelector('[data-test=slot-api-changed]')
                ?.textContent?.trim(),
        ).toBe(
            'API response changed: field total no longer present → Headline value: Unavailable',
        );
        expect(
            r.querySelector('[data-test=slot-api-match]')?.textContent,
        ).toContain('grand_total');
        expect(
            r.querySelector('[data-test=slot-api-match]')?.textContent,
        ).not.toContain('[Use');
        expect(putCalls('slots/headline_value')).toHaveLength(0);

        writeReply = () => {
            current = [headline({ state: 'overridden' })];
            apiChanged = [];

            return answer();
        };
        await click(r.querySelector('[data-test=use-match]'));

        expect(putCalls('slots/headline_value')[0].body).toMatchObject({
            path: '$.grand_total',
        });
        expect($('[data-test=slot-api-changed]')).toBeNull();
    });

    it('shows the message only when there is no match', async () => {
        apiChanged = [
            {
                slot: 'headline_value',
                path: '$.total',
                display: 'total',
                state: 'required_missing',
                match: null,
            },
        ];
        await open([gone()]);

        expect($('[data-test=slot-api-changed]')).not.toBeNull();
        expect($('[data-test=slot-api-match]')).toBeNull();
        expect($('[data-test=use-match]')).toBeNull();
    });
});

describe('Read-only', () => {
    it('renders no picker, Treat as, Undo or Use buttons, but keeps the messages', async () => {
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
        apiChanged = [
            {
                slot: 'headline_value',
                path: '$.total',
                display: 'total',
                state: 'required_missing',
                match: { path: '$.grand_total', display: 'grand_total' },
            },
        ];
        offer([field({ display: 'revenue' })]);
        await open([
            headline({
                state: 'required_missing',
                confidence: null,
                path: null,
                display: null,
            }),
            title({ state: 'type_mismatch', confidence: null }),
        ]);

        expect(text('[data-test=map-blocked]')).toBe(draftLockLabels.readOnly);
        expect($('[data-test=change-field]')).toBeNull();
        expect($('[data-test=treat-as-button]')).toBeNull();
        expect($('[data-test=use-match]')).toBeNull();
        expect($('[data-test=fitting-field]')).toBeNull();
        expect($('[data-test=undo-override]')).toBeNull();
        expect($('[data-test=slot-api-changed]')).not.toBeNull();
        expect(row('title').dataset.state).toBe('type_mismatch');
    });
});

function reads() {
    return calls.filter(
        (c) => c.method === 'GET' && c.url === `${BASE}/mapping`,
    );
}

describe('Review fixes', () => {
    const undoPayload = {
        slot: 'headline_value',
        slot_entry: { path: '$.total', state: 'confirmed' },
        role: { path: '$.data.revenue', previous: null },
        applied: { path: '$.data.revenue', state: 'overridden' },
    };

    async function overridden(): Promise<void> {
        offer(headlineFields());
        await open([
            headline({ state: 'confirmed' }),
            note({ state: 'confirmed' }),
        ]);
        writeReply = () => {
            current = current.map((s) =>
                s.slot === 'headline_value' ? { ...s, state: 'overridden' } : s,
            );

            return answer({ undo: undoPayload });
        };
        await openPicker();
        await click(options()[1]);
    }

    it('keeps one Undo note: a pick on another slot replaces it', async () => {
        await overridden();
        offer([
            field({ display: 'note', type: 'text', effective_type: 'text' }),
        ]);
        writeReply = () =>
            answer({ undo: { ...undoPayload, slot: 'secondary_note' } });
        await openPicker('secondary_note');
        await click(options()[0]);

        expect($$('[data-test=override-note]')).toHaveLength(1);
        expect(
            row('headline_value').querySelector('[data-test=override-note]'),
        ).toBeNull();
    });

    it('drops the note on Re-run', async () => {
        await overridden();
        writeReply = () => answer();
        await click($('[data-test=rerun]'));

        expect($('[data-test=override-note]')).toBeNull();
    });

    it('drops the note on Confirm', async () => {
        offer(headlineFields());
        await open([
            headline({ state: 'confirmed' }),
            note({ state: 'auto_medium' }),
        ]);
        writeReply = () => {
            current = current.map((s) =>
                s.slot === 'headline_value' ? { ...s, state: 'overridden' } : s,
            );

            return answer({ undo: undoPayload });
        };
        await openPicker();
        await click(options()[1]);

        expect($('[data-test=override-note]')).not.toBeNull();

        writeReply = () => answer();
        await click(
            row('secondary_note').querySelector('[data-test=slot-confirm]'),
        );

        expect($('[data-test=override-note]')).toBeNull();
    });

    it('adopts current.revision from a stale undo, so the next write sends it', async () => {
        await overridden();
        writeReply = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.slot_changed' },
                current: { data: { ...draft(), revision: 7 } },
            },
        });
        await click($('[data-test=undo-override]'));
        writeReply = () => answer();
        await click($('[data-test=rerun]'));

        expect(writes('auto-map').at(-1)?.body?.draft_revision).toBe(7);
    });

    it('does not take a 422 without a slot error as a refusal of the pick', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        writeReply = () => ({
            status: 422,
            json: {
                error: { code: 'validation_failed' },
                errors: { draft_revision: ['bad'] },
            },
        });
        await openPicker();
        await click(options()[1]);

        expect(text('[data-test=map-not-saved]')).not.toBe(labels.notPicked);
        expect(text('[data-test=map-not-saved]')).not.toBe('bad');
    });

    it('says a Treat as 422 in its own words', async () => {
        offer([]);
        await open([
            headline({
                state: 'type_mismatch',
                confidence: null,
                path: '$.t',
                display: 't',
            }),
        ]);
        writeReply = () => ({
            status: 422,
            json: { error: { code: 'blocks.invalid_config' }, errors: {} },
        });
        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );
        await click($('[data-test=treat-as-menu] [data-type=date]'));

        expect(text('[data-test=map-not-saved]')).toBe(labels.notTreated);
    });

    it('drops the note when Treat as follows an override of that slot', async () => {
        offer([field({ display: 'rev' })]);
        await open([
            headline({
                state: 'type_mismatch',
                confidence: null,
                path: '$.t',
                display: 't',
            }),
        ]);
        writeReply = () => {
            current = [
                headline({
                    state: 'type_mismatch',
                    confidence: null,
                    path: '$.rev',
                    display: 'rev',
                }),
            ];

            return answer({ undo: undoPayload });
        };
        await click(
            row('headline_value').querySelector('[data-test=fitting-field]'),
        );

        expect($('[data-test=override-note]')).not.toBeNull();

        writeReply = () => answer();
        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );
        await click($('[data-test=treat-as-menu] [data-type=number]'));

        expect($('[data-test=override-note]')).toBeNull();
    });

    it('sends type null for Automatic and marks the current type', async () => {
        offer([
            field({
                display: 't',
                path: '$.t',
                type: 'text',
                effective_type: 'text',
                compatible: false,
                reason: 'x',
            }),
        ]);
        await open([
            headline({
                state: 'type_mismatch',
                confidence: null,
                path: '$.t',
                display: 't',
            }),
        ]);
        writeReply = () => answer();
        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );

        expect(
            $('[data-test=treat-as-menu] [data-type=text]')?.getAttribute(
                'aria-checked',
            ),
        ).toBe('true');

        press($('[data-test=treat-as-menu]')!, 'Escape');
        await settle();

        expect($('[data-test=treat-as-menu]')).toBeNull();

        await click(
            row('headline_value').querySelector('[data-test=treat-as-button]'),
        );
        await click($('[data-test=treat-as-menu] [data-type=auto]'));

        expect(putCalls('field-types')[0].body).toMatchObject({
            path: '$.t',
            type: null,
        });
    });

    it('builds the match sentence with the example when the roles have one, and without it otherwise', async () => {
        apiChanged = [
            {
                slot: 'headline_value',
                path: '$.total',
                display: 'total',
                state: 'required_missing',
                match: { path: '$.grand_total', display: 'grand_total' },
            },
        ];
        roleFields = [
            {
                path: '$.grand_total',
                display: 'grand_total',
                example: '284680',
            },
        ];
        const gone = headline({
            state: 'required_missing',
            confidence: null,
            path: null,
            display: null,
        });

        await open([gone]);

        expect(text('[data-test=slot-api-match]')).toContain(
            'matching value 284680',
        );

        wrapper?.unmount();
        document.body.innerHTML = '';
        roleFields = [];
        await open([gone]);

        expect(text('[data-test=slot-api-match]')).not.toContain(
            '(matching value',
        );
        expect(text('[data-test=slot-api-match]')).toContain('grand_total');
    });

    it('announces the reason of an incompatible option on Enter, and ignores list keys when nothing matches', async () => {
        offer(headlineFields());
        await open([headline({ state: 'confirmed' })]);
        await openPicker();
        press(search(), 'End');
        await settle();
        press(search(), 'Enter');
        await settle();

        expect(polite()).toBe(CURRENCY_REASON);

        await type('zzz');
        press(search(), 'ArrowDown');
        press(search(), 'End');
        await settle();

        expect(search().getAttribute('aria-activedescendant')).toBeNull();
        expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    });

    it('treats an empty blocked reason as read-only on a row', () => {
        const row = mount(SlotRow as never, {
            props: {
                item: headline(),
                blocked: '',
                loadFields: async () => [],
            },
            global: { plugins: [createCatalogue()] },
        });

        expect(row.find('[data-test=change-field]').exists()).toBe(false);
        row.unmount();
    });
});

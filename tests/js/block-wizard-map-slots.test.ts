// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    applyOrder,
    blockingSlots,
    computeOrder,
    confidenceOf,
    filterCounts,
} from '../../resources/js/lib/blockMapping';
import type {
    Blocker,
    ChecklistSlot,
} from '../../resources/js/lib/blockMapping';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    blockMapLabels as labels,
    draftLockLabels,
} from '../../resources/js/locales/labels';

// Story 3.12b (UI stage 2): the slot checklist, confidence, Confirm, Re-run, the filter and Continue gating of wizard step 3.
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

const lowLabel = (over: Partial<ChecklistSlot> = {}) =>
    slot({
        slot: 'kpi_label',
        label: 'KPI label',
        state: 'auto_low',
        confidence: 'low',
        reason: 'Several labels match',
        path: '$.metric',
        display: 'metric',
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
        api_changed: [],
    };
}

type Reply = { status: number; json?: unknown };
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let revision = 1;
let current: ChecklistSlot[] = [];
let baseline: unknown[] = [];
let sampleStatus = 'ok';
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
const rowKeys = () => $$('[data-test=slot-row]').map((r) => r.dataset.slotKey);
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
                                                  fields: [],
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

describe('Slot checklist: the helpers', () => {
    it('orders Low first once, keeps the order after, and counts the filters', () => {
        const slots = [headline(), lowLabel(), title()];
        const groups = checklist(slots).groups;
        const order = computeOrder(groups);

        expect(order).toEqual(['title', 'kpi_label', 'headline_value']);

        const confirmed = checklist([
            headline(),
            lowLabel({ state: 'confirmed' }),
            title(),
        ]).groups;

        expect(
            applyOrder(confirmed, order)
                .flatMap((g) => g.slots)
                .map((s) => s.slot),
        ).toEqual(['title', 'kpi_label', 'headline_value']);
        expect(filterCounts(slots)).toEqual({ all: 3, review: 2, missing: 0 });
        expect(confidenceOf(lowLabel({ state: 'confirmed' }))).toBe('high');
        expect(confidenceOf(slot({ slot: 'x' }))).toBe('none');
        expect(
            blockingSlots(checklist(slots), order).map((b) => b.key),
        ).toEqual(['kpi_label']);
    });
});

describe('Slot checklist: open', () => {
    it('groups the slots, labels each row and shows the reason for a Medium', async () => {
        await open([headline(), title(), note()]);

        expect(
            $$('[data-test=slot-group] h3').map((h) => h.textContent?.trim()),
        ).toEqual(['Header', 'KPI', 'Meta']);
        expect(row('headline_value').getAttribute('role')).toBe('group');
        expect(row('headline_value').getAttribute('aria-label')).toBe(
            'Headline value, required, Auto-mapped, medium confidence',
        );
        expect(row('title').getAttribute('aria-label')).toBe(
            'Title, optional, Auto-mapped, high confidence',
        );
        expect(
            row('headline_value')
                .querySelector('[data-test=required-mark]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');
        expect(
            row('title').querySelector('[data-test=required-mark]'),
        ).toBeNull();
        expect(
            row('headline_value')
                .querySelector('[data-test=slot-confidence]')
                ?.textContent?.replace(/\s+/g, ' ')
                .trim(),
        ).toBe('Confidence: Medium');
        expect(
            row('headline_value')
                .querySelector('[data-test=confidence-meter]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');
        expect(
            row('headline_value').querySelector('[data-test=slot-reason]')
                ?.textContent,
        ).toContain('picked the one not named previous_*');
        expect(
            row('title').querySelector('[data-test=slot-reason]'),
        ).toBeNull();
        expect(text('[data-test=status-summary]')).toBe(
            'Auto-mapped 3 of 3 · 1 confirmed · 0 required missing',
        );
        expect($$('[data-test=sample-badge]')).toHaveLength(1);
        expect(text('[data-test=sample-badge]')).toBe('Sample data');
        expect(reads()).toHaveLength(1);
    });

    it('renders all eight states with their words and no colour-only signal', async () => {
        const states = [
            ['auto_high', 'Auto-mapped', 'high'],
            ['auto_medium', 'Auto-mapped', 'medium'],
            ['auto_low', 'Auto-mapped', 'low'],
            ['confirmed', 'Confirmed', 'high'],
            ['overridden', 'Overridden', 'high'],
            ['optional_empty', 'Optional, empty', 'no'],
            ['required_missing', 'Required, missing', 'no'],
            ['type_mismatch', 'Type mismatch', 'no'],
        ] as const;

        await open(
            states.map(([state], i) =>
                slot({
                    slot: `s${i}`,
                    label: `S${i}`,
                    state,
                    confidence:
                        state === 'auto_medium'
                            ? 'medium'
                            : state === 'auto_low'
                              ? 'low'
                              : state === 'auto_high'
                                ? 'high'
                                : null,
                    reason: 'because',
                    path: '$.a',
                    display: 'a',
                }),
            ),
        );

        states.forEach(([state, word, level], i) => {
            const label = row(`s${i}`).getAttribute('aria-label')!;

            expect(label).toContain(word);
            expect(label).toContain(`${level} confidence`);
            expect(row(`s${i}`).dataset.state).toBe(state);
        });
        expect(text('[data-slot-key=s3] [data-test=confirmed-by-you]')).toBe(
            'Confirmed by you',
        );
    });

    it('pins Low rows first in a group', async () => {
        await open([headline(), lowLabel()]);

        expect(rowKeys()).toEqual(['kpi_label', 'headline_value']);
    });

    it('shows a required-missing row with its suggestion and the baseline field', async () => {
        await open(
            [
                slot({
                    slot: 'headline_value',
                    label: 'Headline',
                    required: true,
                    state: 'required_missing',
                    suggestion: { path: '$.revenue', display: 'revenue' },
                }),
            ],
            [{ path: '$.previous_total', display: 'previous_total' }],
        );

        const missing = row('headline_value');

        expect(missing.className).toContain('border-l-error');
        expect(missing.className).toContain('bg-error-soft');
        expect(
            missing
                .querySelector('[data-test=slot-suggestion]')
                ?.textContent?.trim(),
        ).toBe('Use revenue as Headline');
        expect(text('[data-test=baseline-note]')).toContain('previous_total');
    });
});

describe('Slot checklist: Confirm', () => {
    it('confirms a Medium row, shows Confirmed by you, updates counts and announces', async () => {
        await open([headline(), title()]);

        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );

        expect(writes('slots/headline_value/confirm')).toHaveLength(1);
        expect(writes('slots/headline_value/confirm')[0].body).toMatchObject({
            draft_revision: 1,
            lock_epoch: 1,
        });
        expect(
            row('headline_value').querySelector('[data-test=confirmed-by-you]')
                ?.textContent,
        ).toBe('Confirmed by you');
        expect(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        ).toBeNull();
        expect(text('[data-test=status-summary]')).toBe(
            'Auto-mapped 1 of 2 · 2 confirmed · 0 required missing',
        );
        expect(polite()).toContain(
            'Headline value confirmed. 0 slots still need review.',
        );
    });

    it('never confirms on Enter over the row or the button', async () => {
        await open([headline()]);

        const enter = () =>
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true });

        row('headline_value').focus();
        document.activeElement!.dispatchEvent(enter());
        row('headline_value')
            .querySelector<HTMLElement>('[data-test=slot-confirm]')!
            .dispatchEvent(enter());
        await settle();

        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0);
    });

    it('keeps the pinned order of an auto_low row after it is confirmed', async () => {
        await open([headline(), lowLabel()]);

        expect(rowKeys()).toEqual(['kpi_label', 'headline_value']);

        await click(row('kpi_label').querySelector('[data-test=slot-confirm]'));

        expect(row('kpi_label').dataset.state).toBe('confirmed');
        expect(rowKeys()).toEqual(['kpi_label', 'headline_value']);
        expect(
            row('kpi_label').querySelector('[data-test=slot-confidence]'),
        ).toBeNull();
    });

    it('drops a kept row when focus leaves the list', async () => {
        await open([headline(), note()]);
        await click(
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes('Needs review'),
            )!,
        );
        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );

        expect(rowKeys()).toEqual(['headline_value', 'secondary_note']);

        const outside = document.createElement('button');

        document.body.appendChild(outside);
        outside.focus();
        await settle();

        expect(rowKeys()).toEqual(['secondary_note']);
    });

    it('leaves focus on Confirm and the row unpinned when the confirm fails', async () => {
        await open([headline(), note()]);
        await click(
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes('Needs review'),
            )!,
        );
        writeReply = () => ({ status: 500, json: {} });

        const button = row('headline_value').querySelector<HTMLElement>(
            '[data-test=slot-confirm]',
        )!;

        button.focus();
        await click(button);

        expect(document.activeElement).toBe(button);
        expect(row('headline_value').dataset.state).toBe('auto_medium');
    });

    it('keeps a confirmed row under Needs review while focus is in the list, and announces the remainder', async () => {
        await open([headline(), note()]);
        await click(
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes('Needs review'),
            )!,
        );
        expect(rowKeys()).toEqual(['headline_value', 'secondary_note']);

        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );

        expect(rowKeys()).toEqual(['headline_value', 'secondary_note']);
        expect(document.activeElement).toBe(row('headline_value'));
        expect(polite()).toContain(
            'Headline value confirmed. 1 slot still needs review.',
        );
    });

    it('lets the row go when the filter changes', async () => {
        await open([headline(), note()]);
        const pick = (word: string) =>
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes(word),
            )!;

        await click(pick('Needs review'));
        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );
        await click(pick('All'));
        await click(pick('Needs review'));

        expect(rowKeys()).toEqual(['secondary_note']);
        expect(pick('Needs review').textContent).toContain('(1)');
    });

    it('filters to Missing and says when nothing matches', async () => {
        await open([headline(), title()]);
        await click(
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes('Missing'),
            )!,
        );

        expect(rowKeys()).toEqual([]);
        expect($('[data-test=slot-filter-empty]')).not.toBeNull();
    });
});

describe('Slot checklist: Re-run', () => {
    it('calls auto-map, keeps the confirmed row and re-sorts', async () => {
        await open([
            headline({ state: 'confirmed' }),
            slot({
                slot: 'kpi_label',
                label: 'KPI label',
                state: 'optional_empty',
            }),
        ]);
        rerunSlots = () => [
            headline({ state: 'confirmed' }),
            lowLabel(),
            slot({
                slot: 'subtitle',
                label: 'Subtitle',
                group: 'HEADER',
                state: 'auto_high',
                confidence: 'high',
            }),
        ];

        expect(rowKeys()).toEqual(['headline_value', 'kpi_label']);

        await click($('[data-test=rerun]'));

        expect(writes('auto-map')).toHaveLength(1);
        expect(writes('auto-map')[0].body).toMatchObject({ draft_revision: 1 });
        expect(rowKeys()).toEqual(['subtitle', 'kpi_label', 'headline_value']);
        expect(row('headline_value').dataset.state).toBe('confirmed');
        expect(polite()).toContain(labels.rerunDone);
    });
});

describe('Slot checklist: Continue', () => {
    it('is aria-disabled with the reason and focuses the blocking row', async () => {
        await open([
            slot({
                slot: 'headline_value',
                label: 'Headline value',
                required: true,
                state: 'required_missing',
            }),
            title(),
        ]);

        const go = $('[data-test=slots-continue]')!;

        expect(go.getAttribute('aria-disabled')).toBe('true');
        expect(go.hasAttribute('disabled')).toBe(false);
        expect(text('[data-test=slots-continue-reason]')).toBe(
            'Map Headline value to continue. Optional slots can stay empty.',
        );

        await click(go);

        expect($('[data-test=step-3]')).not.toBeNull();
        expect(document.activeElement).toBe(row('headline_value'));
        expect($('[data-test=blockers-summary]')).toBeNull();
    });

    it('focuses an error summary of links when several slots block', async () => {
        await open([
            slot({
                slot: 'headline_value',
                label: 'Headline value',
                required: true,
                state: 'required_missing',
            }),
            lowLabel(),
        ]);

        await click($('[data-test=slots-continue]'));

        const summary = $('[data-test=blockers-summary]')!;

        expect(summary).not.toBeNull();
        expect(document.activeElement).toBe(summary);
        expect(summary.querySelectorAll('a')).toHaveLength(2);

        await click(summary.querySelectorAll('a')[1]);

        expect(document.activeElement).toBe(row('headline_value'));
        expect(text('[data-test=slots-continue-reason]')).toContain(
            'Confirm KPI label',
        );
    });

    it('does not block on a Medium optional slot and moves to step 4', async () => {
        await open([headline({ state: 'confirmed' }), note()]);

        const go = $('[data-test=slots-continue]')!;

        expect(go.getAttribute('aria-disabled')).toBeNull();
        expect($('[data-test=slots-continue-reason]')).toBeNull();

        await click(go);

        expect($('[data-test=later-step]')).not.toBeNull();
        expect($('[data-test=step-3]')).toBeNull();
        const save = calls.find((c) => c.url === BASE && c.method === 'PUT')!;

        expect(save.body).toMatchObject({ step: 4 });
    });
});

describe('Slot checklist: Continue reasons', () => {
    it('lists several required slots from the catalogue', async () => {
        const missing = (key: string, label: string) =>
            slot({
                slot: key,
                label,
                required: true,
                state: 'required_missing',
            });

        await open([missing('a_value', 'Alpha'), missing('b_value', 'Beta')]);

        expect(text('[data-test=slots-continue-reason]')).toBe(
            'Map 2 required slots to continue: Alpha, Beta. Optional slots can stay empty.',
        );
    });

    it('names a type mismatch and an api_changed blocker, and one that the list does not know', async () => {
        extraBlockers = [
            { slot: 'subtitle', reason: 'api_changed' },
            { slot: 'ghost', reason: 'type_mismatch' },
        ];
        await open([
            slot({
                slot: 'headline_value',
                label: 'Headline value',
                required: true,
                state: 'type_mismatch',
                reason: 'Text, not a number',
            }),
            slot({ slot: 'subtitle', label: 'Subtitle', group: 'HEADER' }),
        ]);

        const reason = text('[data-test=slots-continue-reason]');

        expect(reason).toContain('Fix Headline value, its field does not fit.');
        expect(reason).toContain('Review Subtitle, its field is no longer');
        expect(reason).toContain('Fix ghost');
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('joins two reasons for one slot into one summary link', async () => {
        extraBlockers = [{ slot: 'headline_value', reason: 'api_changed' }];
        await open([headline({ state: 'type_mismatch' }), lowLabel()]);
        await click($('[data-test=slots-continue]'));

        const links = $$('[data-test=blockers-summary] a');

        expect(links).toHaveLength(2);
        expect(
            links.find((l) => l.textContent === 'Headline value')?.parentElement
                ?.textContent,
        ).toContain(
            'its field does not fit, its field is no longer in the response',
        );
    });

    it('does not show the summary again after the blockers clear and return', async () => {
        await open([
            slot({
                slot: 'a_value',
                label: 'Alpha',
                required: true,
                state: 'required_missing',
            }),
            lowLabel(),
        ]);
        await click($('[data-test=slots-continue]'));
        expect($('[data-test=blockers-summary]')).not.toBeNull();

        rerunSlots = () => [headline({ state: 'confirmed' })];
        await click($('[data-test=rerun]'));
        rerunSlots = () => [
            slot({
                slot: 'a_value',
                label: 'Alpha',
                required: true,
                state: 'required_missing',
            }),
            lowLabel(),
        ];
        await click($('[data-test=rerun]'));

        expect($('[data-test=blockers-summary]')).toBeNull();
    });

    it('opens a hidden row from a summary link by showing All', async () => {
        await open([
            slot({
                slot: 'a_value',
                label: 'Alpha',
                required: true,
                state: 'required_missing',
            }),
            lowLabel(),
            title(),
        ]);
        await click($('[data-test=slots-continue]'));
        await click(
            $$('[data-slot=segmented-item]').find((i) =>
                i.textContent?.includes('Needs review'),
            )!,
        );

        expect(rowKeys()).toEqual(['kpi_label']);

        await click(
            $$('[data-test=blockers-summary] a').find(
                (l) => l.textContent === 'Alpha',
            ),
        );

        expect(document.activeElement).toBe(row('a_value'));
    });

    it('blocks Continue when there is no checklist', async () => {
        checklistNull = true;
        await open([headline()]);

        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=slots-continue-reason]')).toBe(
            labels.checklistMissing,
        );

        await click($('[data-test=slots-continue]'));

        expect($('[data-test=step-3]')).not.toBeNull();
    });

    it('has no other Continue on step 3 that skips the gate', async () => {
        await open([headline()]);

        expect($('[data-test=step-3] [data-test=continue]')).toBeNull();
        expect($('[data-test=step-3] button[type=submit]')).toBeNull();
    });
});

describe('Slot checklist: read-only and refusals', () => {
    it('blocks Confirm, Re-run and Continue with the reason when the lock is held elsewhere', async () => {
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
        await open([headline({ state: 'confirmed' }), note()]);

        expect(rowKeys()).toHaveLength(2);
        expect(text('[data-test=map-blocked]')).toBe(draftLockLabels.readOnly);
        expect(
            row('secondary_note')
                .querySelector('[data-test=slot-confirm]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect($('[data-test=rerun]')?.getAttribute('aria-disabled')).toBe(
            'true',
        );
        expect(
            $('[data-test=slots-continue]')?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=slots-continue-reason]')).toBe(
            draftLockLabels.readOnly,
        );

        await click(
            row('secondary_note').querySelector('[data-test=slot-confirm]'),
        );
        await click($('[data-test=rerun]'));
        await click($('[data-test=slots-continue]'));

        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0);
        expect($('[data-test=step-3]')).not.toBeNull();
    });

    it('shows the recovery message when the sample is gone', async () => {
        sampleStatus = 'expired';
        await open([headline()]);

        expect($('[data-test=sample-gone]')).not.toBeNull();
        expect($('[data-test=slot-checklist]')).toBeNull();
        expect($('[data-test=slots-continue]')).toBeNull();
    });

    it('says the change was not saved when Confirm fails, and keeps the row', async () => {
        await open([headline()]);
        writeReply = () => ({ status: 500, json: {} });

        await click(
            row('headline_value').querySelector('[data-test=slot-confirm]'),
        );

        expect($('[data-test=map-not-saved]')).not.toBeNull();
        expect(row('headline_value').dataset.state).toBe('auto_medium');
    });
});

function reads() {
    return calls.filter(
        (c) => c.method === 'GET' && c.url === `${BASE}/mapping`,
    );
}

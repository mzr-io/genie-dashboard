// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import TransformsSection from '../../resources/js/components/wizard/TransformsSection.vue';
import {
    canAdd,
    inStageOrder,
    judge,
    shortName,
} from '../../resources/js/lib/blockTransforms';
import type { TransformStep } from '../../resources/js/lib/blockTransforms';
import { resetAnnouncer } from '../../resources/js/lib/announce';

// Story 5.1: the Transforms section of Map Data and its client helpers.
const fields = [
    { path: '$.data[*].status', display: 'data[].status' },
    { path: '$.data[*].revenue', display: 'data[].revenue' },
];
const filter: TransformStep = {
    type: 'filter',
    field: '$.data[*].status',
    operator: 'is',
    value: 'Approved',
};

describe('transform helpers', () => {
    it('orders steps filter, sort, top-N whatever order they were added in', () => {
        const steps: TransformStep[] = [
            { type: 'top_n', count: 5 },
            { type: 'sort', field: '$.a', direction: 'desc' },
            filter,
        ];

        expect(inStageOrder(steps).map((s) => s.type)).toEqual([
            'filter',
            'sort',
            'top_n',
        ]);
    });

    it('limits each kind of step and names a field by its last name', () => {
        const filters = Array.from({ length: 5 }, () => filter);

        expect(canAdd(filters, 'filter')).toBe(false);
        expect(canAdd(filters, 'sort')).toBe(true);
        expect(canAdd([{ type: 'top_n', count: 1 }], 'top_n')).toBe(false);
        expect(shortName('$.data[*].status')).toBe('status');
    });

    it('judges a numeric comparison with no number and a bad count', () => {
        expect(
            judge([
                { ...filter, operator: 'gt', value: 'abc' },
                { type: 'top_n', count: 0 },
                { ...filter, operator: 'gt', value: '1.5' },
            ]),
        ).toEqual({ 0: 'not_numeric_value', 1: 'count' });
    });
});

describe('group and aggregate helpers', () => {
    it('orders filter, group, aggregate, sort, top-N and limits each kind', () => {
        const steps: TransformStep[] = [
            { type: 'top_n', count: 5 },
            { type: 'sort', field: '$.a', direction: 'desc' },
            { type: 'aggregate', function: 'sum', field: '$.b' },
            { type: 'group', fields: ['$.a'] },
            filter,
        ];

        expect(inStageOrder(steps).map((s) => s.type)).toEqual([
            'filter',
            'group',
            'aggregate',
            'sort',
            'top_n',
        ]);
        expect(canAdd([{ type: 'group', fields: ['$.a'] }], 'group')).toBe(
            false,
        );
        expect(
            canAdd(
                Array.from({ length: 5 }, () => ({
                    type: 'aggregate' as const,
                    function: 'sum' as const,
                    field: '$.b',
                })),
                'aggregate',
            ),
        ).toBe(false);
    });

    it('judges a group with a repeated or no field', () => {
        expect(
            judge([
                { type: 'group', fields: [] },
                { type: 'group', fields: ['$.a', '$.a'] },
            ]),
        ).toEqual({ 0: 'group_fields', 1: 'group_fields' });
    });
});

describe('TransformsSection', () => {
    let wrapper: VueWrapper | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        resetAnnouncer();
    });

    function mountSection(props: Record<string, unknown> = {}): VueWrapper {
        wrapper = mount(TransformsSection as never, {
            props: {
                steps: [],
                fields,
                faults: {},
                counts: null,
                ...props,
            },
            attachTo: document.body,
        });

        return wrapper;
    }

    it('says what a step does, counts the rows in and out and names every control', async () => {
        mountSection({
            steps: [filter, { type: 'top_n', count: 5 }],
            counts: { input: 36, steps: [24, 5] },
        });
        await flushPromises();

        const rows = Array.from(
            document.querySelectorAll('[data-test=transform-row]'),
        );

        expect(rows).toHaveLength(2);
        expect(
            rows[0].querySelector('[data-test=stage-label]')?.textContent,
        ).toBe('FILTER');
        expect(
            rows[0]
                .querySelector('[data-test=step-sentence]')
                ?.textContent?.trim(),
        ).toBe('Keep rows where status is Approved');
        expect(
            rows[0]
                .querySelector('[data-test=step-counts]')
                ?.textContent?.trim(),
        ).toBe('36 → 24 rows');
        expect(
            rows[1]
                .querySelector('[data-test=step-counts]')
                ?.textContent?.trim(),
        ).toBe('24 → 5 rows');
        expect(
            rows[0]
                .querySelector('[data-test=filter-field]')
                ?.getAttribute('aria-label'),
        ).toBe('Filter field');
        expect(
            rows[0]
                .querySelector('[data-test=filter-operator]')
                ?.getAttribute('aria-label'),
        ).toBe('Filter operator');
        expect(
            rows[0]
                .querySelector('[data-test=filter-value]')
                ?.getAttribute('aria-label'),
        ).toBe('Filter value');
        expect(
            rows[1]
                .querySelector('[data-test=topn-count]')
                ?.getAttribute('aria-label'),
        ).toBe('Top-N count');
        expect(
            rows[0]
                .querySelector('[data-test=remove-step]')
                ?.getAttribute('aria-label'),
        ).toBe('Remove step: Keep rows where status is Approved');

        const described = rows[0]
            .querySelector('[data-test=filter-field]')
            ?.getAttribute('aria-describedby');

        expect(document.getElementById(described ?? '')?.textContent).toContain(
            'field of each row',
        );
    });

    it('adds a step at its stage position and removes one, focusing the next', async () => {
        const w = mountSection({
            steps: [filter, { type: 'top_n', count: 5 }],
        });

        (document.querySelector('[data-test=add-step]') as HTMLElement).click();
        await flushPromises();
        (document.querySelector('[data-test=add-sort]') as HTMLElement).click();
        await flushPromises();

        const added = w.emitted('change')?.[0]?.[0] as TransformStep[];

        expect(added.map((s) => s.type)).toEqual(['filter', 'sort', 'top_n']);

        await w.setProps({ steps: added });
        await flushPromises();
        (
            document.querySelectorAll(
                '[data-test=remove-step]',
            )[0] as HTMLElement
        ).click();
        await flushPromises();

        const removed = w.emitted('change')?.[1]?.[0] as TransformStep[];

        expect(removed.map((s) => s.type)).toEqual(['sort', 'top_n']);
    });

    it('shows a step the sample cannot satisfy in its row, with the reason inline', async () => {
        mountSection({
            steps: [filter],
            faults: {
                0: { reason: 'field_missing', field: '$.data[*].status' },
            },
        });
        await flushPromises();

        const row = document.querySelector(
            '[data-test=transform-row]',
        ) as HTMLElement;

        expect(row.getAttribute('data-fault')).toBe('true');
        expect(
            row.querySelector('[data-test=step-fault]')?.textContent,
        ).toContain('isn’t in the response');
        expect(
            row
                .querySelector('[data-test=filter-value]')
                ?.getAttribute('aria-invalid'),
        ).toBe('true');
    });

    it('commits a value when the field is left and blocks every control while nothing can be changed', async () => {
        const w = mountSection({ steps: [filter] });
        const input = document.querySelector(
            '[data-test=filter-value]',
        ) as HTMLInputElement;

        input.value = 'Pending';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect(
            (w.emitted('change')![0][0] as TransformStep[])[0],
        ).toMatchObject({ value: 'Pending' });

        await w.setProps({ blocked: 'Read only' });
        expect(
            document
                .querySelector('[data-test=add-step]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(
            (
                document.querySelector(
                    '[data-test=filter-value]',
                ) as HTMLInputElement
            ).disabled,
        ).toBe(true);
    });

    it('offers no step to add while the sample has no row fields', async () => {
        mountSection({ fields: [] });
        await flushPromises();

        expect(
            document
                .querySelector('[data-test=add-step]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(document.querySelector('[data-test=add-reason]')).not.toBeNull();
    });
});

describe('TransformsSection: group and aggregate', () => {
    let wrapper: VueWrapper | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        resetAnnouncer();
    });

    const steps: TransformStep[] = [
        { type: 'group', fields: ['$.data[*].status'] },
        { type: 'aggregate', function: 'sum', field: '$.data[*].revenue' },
        { type: 'sort', field: '$.data[*].revenue', direction: 'desc' },
        { type: 'top_n', count: 5 },
    ];

    function mountSection(props: Record<string, unknown> = {}): VueWrapper {
        wrapper = mount(TransformsSection as never, {
            props: { steps, fields, faults: {}, counts: null, ...props },
            attachTo: document.body,
        });

        return wrapper;
    }

    it('says what a group and an aggregate do and counts 36 → 12 rows', async () => {
        mountSection({ counts: { input: 36, steps: [12, 12, 12, 5] } });
        await flushPromises();

        const sentences = Array.from(
            document.querySelectorAll('[data-test=step-sentence]'),
        ).map((e) => e.textContent?.trim());
        const counts = Array.from(
            document.querySelectorAll('[data-test=step-counts]'),
        ).map((e) => e.textContent?.trim());

        expect(sentences).toEqual([
            'Group by status',
            'SUM of revenue',
            'Sort by revenue descending',
            'Keep the top 5 rows',
        ]);
        expect(counts[0]).toBe('36 → 12 rows');
        expect(
            Array.from(
                document.querySelectorAll(
                    '[data-test=aggregate-function] option',
                ),
            ).map((o) => o.textContent?.trim()),
        ).toEqual(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']);
    });

    it('shows "Can\'t SUM a text field" on the aggregate and offers a Sort only the grouped and aggregated fields', async () => {
        mountSection({
            faults: {
                1: {
                    reason: 'not_numeric',
                    field: '$.data[*].status',
                    function: 'sum',
                    field_type: 'text',
                },
            },
        });
        await flushPromises();

        const row = document.querySelectorAll('[data-test=transform-row]')[1];

        expect(
            row.querySelector('[data-test=step-fault]')?.textContent?.trim(),
        ).toBe("Can't SUM a text field");
        expect(
            Array.from(
                document.querySelectorAll('[data-test=sort-field] option'),
            ).map((o) => o.textContent?.trim()),
        ).toEqual(['status', 'revenue']);
    });

    it('adds a Group and an Aggregate in their stage position and the group takes up to three fields', async () => {
        const w = mountSection({ steps: [{ type: 'top_n', count: 5 }] });

        (document.querySelector('[data-test=add-step]') as HTMLElement).click();
        await flushPromises();
        (
            document.querySelector('[data-test=add-aggregate]') as HTMLElement
        ).click();
        await flushPromises();

        const added = w.emitted('change')![0][0] as TransformStep[];

        expect(added.map((s) => s.type)).toEqual(['aggregate', 'top_n']);

        await w.setProps({
            steps: [{ type: 'group', fields: ['$.data[*].status'] }],
        });
        await flushPromises();
        (
            document.querySelector('[data-test=group-add-field]') as HTMLElement
        ).click();
        await flushPromises();

        expect(
            (w.emitted('change')![1][0] as TransformStep[])[0],
        ).toMatchObject({
            type: 'group',
            fields: ['$.data[*].status', '$.data[*].revenue'],
        });
    });
});

describe('TransformsSection: calculated fields', () => {
    let wrapper: VueWrapper | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        resetAnnouncer();
    });

    const calc: TransformStep = {
        type: 'calc',
        name: 'margin',
        level: 'row',
        source: '(revenue - cost) / revenue',
    };
    const ratio: TransformStep = {
        type: 'calc',
        name: 'completion',
        level: 'aggregate',
        source: '',
        ratio: {
            numerator: '$.data[*].revenue',
            denominator: '$.data[*].status',
        },
    };

    function mountSection(props: Record<string, unknown> = {}): VueWrapper {
        wrapper = mount(TransformsSection as never, {
            props: {
                steps: [calc, ratio],
                fields,
                faults: {},
                counts: null,
                ...props,
            },
            attachTo: document.body,
        });

        return wrapper;
    }

    it('orders a row calc before the group and an aggregate calc after the aggregate', () => {
        const steps: TransformStep[] = [
            { type: 'sort', field: '$.a', direction: 'asc' },
            { ...calc, level: 'aggregate' },
            { type: 'aggregate', function: 'sum', field: '$.b' },
            { type: 'group', fields: ['$.a'] },
            calc,
        ];

        expect(
            inStageOrder(steps).map((s) =>
                s.type === 'calc' ? `calc:${s.level}` : s.type,
            ),
        ).toEqual(['calc:row', 'group', 'aggregate', 'calc:aggregate', 'sort']);
    });

    it('says what a calculated field and a ratio are, and notes how a ratio aggregates', async () => {
        mountSection();
        await flushPromises();

        const sentences = Array.from(
            document.querySelectorAll('[data-test=step-sentence]'),
        ).map((e) => e.textContent?.trim());

        expect(sentences).toEqual([
            'margin = (revenue - cost) / revenue',
            'completion = revenue ÷ status (ratio)',
        ]);
        expect(
            document.querySelector('[data-test=ratio-note]')?.textContent,
        ).toContain('sums numerator and denominator');
        expect(
            (
                document.querySelectorAll(
                    '[data-test=calc-level]',
                )[1] as HTMLSelectElement
            ).disabled,
        ).toBe(true);
        expect(
            document
                .querySelector('[data-test=calc-expression]')
                ?.getAttribute('aria-label'),
        ).toBe('Expression');
    });

    it("commits an expression on change, takes the old tree away, and shows the server's refusal with its position", async () => {
        const w = mountSection({
            steps: [{ ...calc, ast: { t: 'num' } }],
            faults: {
                0: {
                    reason: 'message',
                    field: null,
                    message: 'Unexpected end (position 14).',
                },
            },
        });
        await flushPromises();

        expect(
            document.querySelector('[data-test=step-fault]')?.textContent,
        ).toContain('position 14');

        const input = document.querySelector(
            '[data-test=calc-expression]',
        ) as HTMLInputElement;

        input.value = 'ROUND(revenue, 2)';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        const sent = (
            w.emitted('change')![0][0] as TransformStep[]
        )[0] as Record<string, unknown>;

        expect(sent.source).toBe('ROUND(revenue, 2)');
        expect(sent.ast).toBeUndefined();
    });

    it('switches to a ratio and back, and adds a calculated field with a free name', async () => {
        const w = mountSection({ steps: [calc] });

        const kind = document.querySelector(
            '[data-test=calc-kind]',
        ) as HTMLSelectElement;

        kind.value = 'ratio';
        kind.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        const ratioStep = (
            w.emitted('change')![0][0] as TransformStep[]
        )[0] as Record<string, unknown>;

        expect(ratioStep.level).toBe('aggregate');
        expect(ratioStep.ratio).toEqual({
            numerator: '$.data[*].status',
            denominator: '$.data[*].revenue',
        });

        (document.querySelector('[data-test=add-step]') as HTMLElement).click();
        await flushPromises();
        (document.querySelector('[data-test=add-calc]') as HTMLElement).click();
        await flushPromises();

        const added = w.emitted('change')![1][0] as TransformStep[];

        expect(added.map((s) => (s.type === 'calc' ? s.name : s.type))).toEqual(
            ['margin', 'calc_1'],
        );
    });

    it('says Escape in the expression to the page', async () => {
        const w = mountSection({ steps: [calc] });

        (
            document.querySelector('[data-test=calc-expression]') as HTMLElement
        ).dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();

        expect(w.emitted('cancelCalc')?.[0]).toEqual(['margin']);
    });
});

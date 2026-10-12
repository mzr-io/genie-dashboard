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

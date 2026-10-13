// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import TableRenderer from '@block-types/table/v1/Renderer.vue';
import type { TablePayload } from '@block-types/table/v1/payload';
import { cellText, compareCells } from '@block-types/table/v1/table';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { tableFor } from '../../resources/js/lib/blockRenderers';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 5.12: the Table Block: a real table, sort cycle, pages, stable keys, bands, missing values and the table of "View as table".
function payload(rows = 48, over: Partial<TablePayload> = {}): TablePayload {
    return {
        state: 'normal',
        title: 'Orders',
        subtitle: null,
        icon: null,
        footerAction: null,
        partial: false,
        pageSize: 10,
        periodKey: null,
        emptyDisplay: null,
        staleSince: null,
        errorMessage: null,
        columns: [
            {
                key: 'col_1',
                header: 'Customer',
                align: 'left',
                width: null,
                sortable: true,
                format: null,
            },
            {
                key: 'col_2',
                header: 'Amount',
                align: 'right',
                width: null,
                sortable: true,
                format: null,
            },
        ],
        rows: Array.from({ length: rows }, (_, n) => ({
            key: `k:${n}`,
            cells: [`C${String(n).padStart(2, '0')}`, String((n * 7) % 13)],
            highlight: null,
        })),
        ...over,
    };
}

const mountTable = (p: TablePayload) =>
    mount(TableRenderer, {
        props: { payload: p },
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });

describe('the table', () => {
    it('is a real table with a caption, column headers and the first page', () => {
        const wrapper = mountTable(payload());

        expect(wrapper.find('table').exists()).toBe(true);
        expect(wrapper.find('caption').text()).toBe('Orders');
        expect(wrapper.findAll('th[scope=col]')).toHaveLength(2);
        expect(wrapper.findAll('tbody tr')).toHaveLength(10);
        expect(wrapper.get('[data-test=table-range]').text()).toBe(
            '1–10 of 48',
        );
        wrapper.unmount();
    });

    it('cycles ascending, descending, data order and sets aria-sort on the sortable header only', async () => {
        const wrapper = mountTable(payload(5));
        const button = wrapper.get('[data-test=sort-col_2]');
        const th = () => wrapper.findAll('th')[1];

        await button.trigger('click');
        expect(th().attributes('aria-sort')).toBe('ascending');
        expect(wrapper.get('[data-test=table-announce]').text()).toBe(
            'Sorted by Amount, ascending',
        );
        await button.trigger('click');
        expect(th().attributes('aria-sort')).toBe('descending');
        await button.trigger('click');
        expect(th().attributes('aria-sort')).toBeUndefined();
        wrapper.unmount();
    });

    it('pages with Previous and Next disabled at the ends and goes back to the last page when rows shrink', async () => {
        const wrapper = mountTable(payload(48));

        expect(
            wrapper.get('[data-test=table-prev]').attributes('aria-disabled'),
        ).toBe('true');
        for (let n = 0; n < 4; n++) {
            await wrapper.get('[data-test=table-next]').trigger('click');
        }
        expect(wrapper.get('[data-test=table-range]').text()).toBe(
            '41–48 of 48',
        );
        expect(
            wrapper.get('[data-test=table-next]').attributes('aria-disabled'),
        ).toBe('true');

        await wrapper.setProps({ payload: payload(35) });
        await nextTick();
        expect(wrapper.get('[data-test=table-range]').text()).toBe(
            '31–35 of 35',
        );
        wrapper.unmount();
    });

    it('starts again at the first page when the period changes', async () => {
        const wrapper = mountTable(payload(48, { periodKey: 'a..b' }));

        await wrapper.get('[data-test=table-next]').trigger('click');
        expect(wrapper.get('[data-test=table-range]').text()).toBe(
            '11–20 of 48',
        );
        await wrapper.setProps({ payload: payload(48, { periodKey: 'c..d' }) });
        expect(wrapper.get('[data-test=table-range]').text()).toBe(
            '1–10 of 48',
        );
        wrapper.unmount();
    });

    it('shows a dash for a missing cell and the band word in the first cell', () => {
        const p = payload(2);
        p.rows[0].cells[1] = null;
        p.rows[1].highlight = {
            text: 'At risk',
            color: 'warning',
            icon: 'triangle-alert',
        };
        p.partial = true;
        const wrapper = mountTable(p);
        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].findAll('td')[1].text()).toBe('—');
        expect(rows[1].find('[data-test=row-band]').text()).toContain(
            'At risk',
        );
        wrapper.unmount();
    });
});

describe('the cells', () => {
    it('formats a decimal exactly and leaves text alone', () => {
        const fmt = {
            kind: 'number' as const,
            decimalPlaces: 2,
            thousandsSeparator: true,
            currencyCode: null,
            currencyDisplay: 'symbol' as const,
            datePattern: 'medium',
            unitsSuffix: null,
        };

        expect(cellText('1234.5', fmt, 'en')).toBe('1,234.50');
        expect(cellText('n/a', fmt, 'en')).toBe('n/a');
        expect(cellText(null, fmt, 'en')).toBe('—');
    });

    it('orders decimals by value, text by the locale and missing last', () => {
        expect(compareCells('9', '10', 'en')).toBeLessThan(0);
        expect(compareCells('b', 'a', 'en')).toBeGreaterThan(0);
        expect(compareCells(null, '1', 'en')).toBeGreaterThan(0);
    });

    it('builds the View as table rows from the payload', () => {
        const model = tableFor('table')?.table(payload(2), 'en');

        expect(model?.columns.map((c) => c.label)).toEqual([
            'Customer',
            'Amount',
        ]);
        expect(model?.rows).toHaveLength(2);
    });
});

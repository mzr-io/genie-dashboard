// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import ListRenderer from '@block-types/list/v1/Renderer.vue';
import type { ListPayload } from '@block-types/list/v1/payload';
import ProgressRenderer from '@block-types/progress-list/v1/Renderer.vue';
import type { ProgressListPayload } from '@block-types/progress-list/v1/payload';
import { percentText, spoken } from '@block-types/progress-list/v1/table';
import { describe, expect, it } from 'vitest';
import { tableFor } from '../../resources/js/lib/blockRenderers';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { expandKpiPayload } from '../../resources/js/lib/textTemplate';

// Story 5.13: the List and Progress list Blocks: rows, avatars, exact percents, bands, clamped bars, spoken text and per-row templates.
const base = {
    state: 'normal' as const,
    title: 'Projects',
    subtitle: null,
    icon: null,
    footerAction: null,
    partial: false,
    emptyDisplay: null,
    staleSince: null,
    errorMessage: null,
};

function list(over: Partial<ListPayload> = {}): ListPayload {
    return {
        ...base,
        valueFormat: null,
        metaFormat: null,
        rows: [
            {
                key: 'k:1',
                title: 'Acme',
                subtitle: 'Retail · 12 days',
                avatar: { initials: 'AL', tint: 2 },
                icon: null,
                value: '1200.5',
                meta: 'up',
                status: null,
            },
            {
                key: 'k:2',
                title: '<img src=x onerror=alert(1)>',
                subtitle: null,
                avatar: null,
                icon: 'star',
                value: null,
                meta: null,
                status: 'Open',
            },
        ],
        ...over,
    };
}

function progress(
    rows: ProgressListPayload['rows'],
    over: Partial<ProgressListPayload> = {},
): ProgressListPayload {
    return { ...base, rows, ...over };
}

const global = { plugins: [createCatalogue()] };

describe('the list', () => {
    it('draws each item with its parts and keeps hostile text literal', () => {
        const wrapper = mount(ListRenderer, {
            props: { payload: list() },
            global,
        });
        const rows = wrapper.findAll('[data-test=list-row]');

        expect(rows).toHaveLength(2);
        expect(rows[0].get('[data-test=list-title]').text()).toBe('Acme');
        expect(rows[0].get('[data-test=list-subtitle]').text()).toBe(
            'Retail · 12 days',
        );
        expect(rows[0].get('[data-test=avatar-initials]').text()).toBe('AL');
        expect(
            rows[0].get('[data-test=avatar-initials]').attributes('data-tint'),
        ).toBe('2');
        expect(rows[0].get('[data-test=list-value]').text()).toBe('1200.5');
        expect(rows[1].get('[data-test=list-title]').text()).toBe(
            '<img src=x onerror=alert(1)>',
        );
        expect(rows[1].find('img').exists()).toBe(false);
        expect(rows[1].get('[data-test=list-status]').text()).toBe('Open');
    });

    it('is a table for "View as table"', () => {
        const model = tableFor('list')?.table(list() as never, 'en');

        expect(model?.columns.map((c) => c.key)).toEqual([
            'item_title',
            'item_subtitle',
            'item_avatar',
            'item_value',
            'item_meta',
            'item_status',
        ]);
        expect(model?.rows[0][0]).toBe('Acme');
    });
});

describe('the progress list', () => {
    const rows = [
        {
            key: 'k:a',
            title: 'Website redesign',
            caption: '12/16 tasks',
            percent: '75',
            fill: '75',
            band: null,
        },
        {
            key: 'k:b',
            title: 'Billing',
            caption: null,
            percent: '130',
            fill: '100',
            band: {
                text: 'At risk',
                color: 'error' as const,
                icon: 'triangle-alert',
            },
        },
        {
            key: 'k:c',
            title: 'Empty total',
            caption: null,
            percent: null,
            fill: null,
            band: null,
        },
    ];

    it('shows the percent, the true percent over 100 and a dash, the bar aria-hidden', () => {
        const wrapper = mount(ProgressRenderer, {
            props: { payload: progress(rows, { partial: true }) },
            global,
        });
        const items = wrapper.findAll('[data-test=progress-row]');

        expect(items[0].get('[data-test=progress-percent]').text()).toBe('75%');
        expect(items[0].get('[data-test=progress-caption]').text()).toBe(
            '12/16 tasks',
        );
        expect(
            items[0].get('[data-test=progress-fill]').attributes('style'),
        ).toContain('width: 75%');
        expect(items[1].get('[data-test=progress-percent]').text()).toBe(
            '130%',
        );
        expect(
            items[1].get('[data-test=progress-fill]').attributes('style'),
        ).toContain('width: 100%');
        expect(items[1].get('[data-test=progress-band]').text()).toContain(
            'At risk',
        );
        expect(items[2].get('[data-test=progress-percent]').text()).toBe('—');
        expect(
            items[0]
                .get('[data-test=progress-track]')
                .attributes('aria-hidden'),
        ).toBe('true');
    });

    it('is read as the title, the caption with "of", and the percent', () => {
        expect(spoken('Website redesign', '12/16 tasks', '75', 'en')).toBe(
            'Website redesign, 12 of 16 tasks, 75%',
        );
        expect(percentText(null, 'en')).toBe('—');
    });
});

describe('templates written once per row', () => {
    it('expands the tokens of each row text and leaves other rows alone', () => {
        const payload = progress([
            {
                key: 'k:a',
                title: 'A',
                caption: '{#12|integer}/{#16|integer} tasks',
                percent: '75',
                fill: '75',
                band: null,
            },
            {
                key: 'k:b',
                title: 'B',
                caption: 'plain',
                percent: '10',
                fill: '10',
                band: null,
            },
        ]);
        const made = expandKpiPayload(
            payload as never,
            {},
        ) as never as ProgressListPayload;

        expect(made.rows[0].caption).toBe('12/16 tasks');
        expect(made.rows[1]).toBe(payload.rows[1]);
    });
});

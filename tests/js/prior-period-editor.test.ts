// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PresentationEditor from '../../resources/js/components/wizard/PresentationEditor.vue';
import { judge } from '../../resources/js/lib/blockPresentation';
import type { PresentationRules } from '../../resources/js/lib/blockPresentation';

// Story 5.6b: the comparison source "Prior-period request" with its prior window, and its client-side rules (the server judges the same).
const item = {
    slot: 'comparison',
    label: 'Comparison value or change %',
    group: 'kpi',
    required: false,
    state: 'confirmed',
    value_type: 'number',
    path: null,
    display: null,
    example: null,
};

function editor(rules: PresentationRules = {}) {
    return mount(PresentationEditor, {
        props: {
            item: item as never,
            rules,
            errors: {},
            headline: { ratio: false, example: '284680' },
        },
    });
}

describe('prior-period comparison source', () => {
    it('writes the prior-period source with the previous-period window when chosen', async () => {
        const w = editor();

        await w
            .findAll('[data-test=comparison-source-control] [role=radio]')[1]
            .trigger('click');

        expect(w.emitted('change')?.[0][0]).toEqual({
            slots: {
                comparison: {
                    source: 'prior_period',
                    window: { kind: 'previous_period' },
                },
            },
        });
    });

    it('shows the window picker, and the offset and unit only for a custom offset', async () => {
        const prior: PresentationRules = {
            slots: {
                comparison: {
                    source: 'prior_period',
                    window: { kind: 'previous_period' },
                },
            },
        };
        const w = editor(prior);

        expect(w.find('[data-test=window-kind]').exists()).toBe(true);
        expect(w.find('[data-test=window-amount]').exists()).toBe(false);

        await w.get('[data-test=window-kind]').setValue('custom_offset');

        expect(w.emitted('change')?.[0][0]).toEqual({
            slots: {
                comparison: {
                    source: 'prior_period',
                    window: { kind: 'custom_offset', amount: 1, unit: 'month' },
                },
            },
        });

        const custom = editor({
            slots: {
                comparison: {
                    source: 'prior_period',
                    window: { kind: 'custom_offset', amount: 3, unit: 'week' },
                },
            },
        });

        expect(custom.find('[data-test=window-amount]').exists()).toBe(true);
        expect(custom.find('[data-test=window-unit]').exists()).toBe(true);
    });

    it('returns to the mapped source by clearing the source and window', async () => {
        const w = editor({
            slots: {
                comparison: {
                    source: 'prior_period',
                    window: { kind: 'same_period_last_year' },
                },
            },
        });

        await w
            .findAll('[data-test=comparison-source-control] [role=radio]')[0]
            .trigger('click');

        expect(w.emitted('change')?.[0][0]).toEqual({});
    });
});

describe('prior-period rules', () => {
    it('needs a window, and a whole offset of 1 to 366 with a unit', () => {
        expect(
            Object.keys(
                judge({ slots: { comparison: { source: 'prior_period' } } }),
            ),
        ).toEqual(['config.presentation.slots.comparison.window']);

        expect(
            Object.keys(
                judge({
                    slots: {
                        comparison: {
                            source: 'prior_period',
                            window: { kind: 'custom_offset', amount: 0 },
                        },
                    },
                }),
            ).sort(),
        ).toEqual([
            'config.presentation.slots.comparison.window.amount',
            'config.presentation.slots.comparison.window.unit',
        ]);

        expect(
            judge({
                slots: {
                    comparison: {
                        source: 'prior_period',
                        window: {
                            kind: 'custom_offset',
                            amount: 12,
                            unit: 'month',
                        },
                    },
                },
            }),
        ).toEqual({});
    });

    it('keeps a source and a window on the comparison slot only', () => {
        const faults = judge({
            slots: {
                headline_value: {
                    source: 'prior_period',
                    window: { kind: 'previous_period' },
                },
            },
        });

        expect(Object.keys(faults).sort()).toEqual([
            'config.presentation.slots.headline_value.source',
            'config.presentation.slots.headline_value.window',
        ]);
    });
});

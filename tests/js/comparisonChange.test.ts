import { describe, expect, it } from 'vitest';
import {
    comparisonChange,
    judge,
} from '../../resources/js/lib/blockPresentation';

// Story 5.5: the example change of a comparison mode is exact (a decimal string, never a float), and a mode belongs to the comparison slot only.
describe('comparison change', () => {
    it.each([
        ['pct_change', '284680', '249195', '14.239852'],
        ['pct_change', '90', '100', '-10'],
        ['absolute_delta', '90', '100', '-10'],
        ['absolute_delta', '5.50', '5.5', '0'],
        ['pp_delta', '72', '70', '2'],
    ] as const)('%s of %s over %s is %s', (mode, current, previous, want) => {
        expect(comparisonChange(mode, current, previous)).toBe(want);
    });

    it('has no change for a missing value or a zero previous percentage', () => {
        expect(comparisonChange('pct_change', '10', '0')).toBeNull();
        expect(comparisonChange('absolute_delta', '10', null)).toBeNull();
        expect(comparisonChange('absolute_delta', 'n/a', '1')).toBeNull();
    });

    it('keeps a mode on the comparison slot only', () => {
        const faults = judge({
            slots: {
                headline_value: { mode: 'pct_change' },
                comparison: { mode: 'pct_change' },
            },
        });

        expect(Object.keys(faults)).toEqual([
            'config.presentation.slots.headline_value.mode',
        ]);
    });
});

import { describe, expect, it } from 'vitest';
import { judge } from '../../resources/js/lib/blockTransforms';
import type { TransformStep } from '../../resources/js/lib/blockTransforms';

// Story 5.11: "Group the rest as Other" on a Top-N step needs a Group step and a label of up to 40 characters.
const group: TransformStep = { type: 'group', fields: ['$.data[*].cat'] };

describe('top-N Other', () => {
    it('is fine with a group step and refused without one', () => {
        const top: TransformStep = { type: 'top_n', count: 5, other: true };

        expect(judge([group, top])).toEqual({});
        expect(judge([top])).toEqual({ 0: 'other_group' });
    });

    it('refuses a label that is too long and keeps a plain Top-N as it was', () => {
        expect(
            judge([
                group,
                {
                    type: 'top_n',
                    count: 5,
                    other: true,
                    other_label: 'x'.repeat(41),
                },
            ]),
        ).toEqual({ 1: 'other_label' });
        expect(judge([{ type: 'top_n', count: 5 }])).toEqual({});
    });
});

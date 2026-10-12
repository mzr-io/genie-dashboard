import { describe, expect, it } from 'vitest';
import {
    canNarrow,
    canWiden,
    clamp,
    describeMove,
    moved,
    place,
    resizeStep,
    resized,
    resolve,
    sameLayout,
    step,
} from '../../resources/js/lib/layout';
import type { Geometry, Layout } from '../../resources/js/lib/layout';
import cases from '../Fixtures/layout-resolve.json';

// Story 4.11: the browser's layout rules. The settling cases are the server's own (tests/Fixtures/layout-resolve.json, run by Pest too), so what a
// person sees while dragging is what the server will save.
describe('resolve', () => {
    for (const c of cases as Array<{
        name: string;
        moved: Layout;
        others: Layout;
        expected: Layout;
    }>) {
        it(c.name, () => {
            expect(resolve(c.moved, c.others)).toEqual(c.expected);
        });
    }
});

describe('clamp', () => {
    const base: Geometry = { col: 0, row: 0, width: 3, height: 'small' };

    it('snaps a width to the nearest preset within limits and keeps the rectangle in the grid', () => {
        expect(clamp({ ...base, col: 10, width: 6 })).toEqual({
            col: 6,
            row: 0,
            width: 6,
            height: 'small',
        });
        expect(clamp({ ...base, width: 5 })).toEqual({ ...base, width: 4 });
        expect(
            clamp(
                { ...base, width: 12 },
                {
                    minWidth: 3,
                    maxWidth: 6,
                    minHeight: 'small',
                    maxHeight: 'large',
                },
            ).width,
        ).toBe(6);
        expect(clamp({ ...base, row: -4, col: -2 })).toEqual(base);
    });

    it("keeps the height inside the Block's limits", () => {
        const limits = {
            minWidth: 3,
            maxWidth: 12,
            minHeight: 'medium' as const,
            maxHeight: 'medium' as const,
        };

        expect(clamp({ ...base, height: 'large' }, limits).height).toBe(
            'medium',
        );
        expect(clamp({ ...base, height: 'small' }, limits).height).toBe(
            'medium',
        );
    });
});

describe('moving and resizing by a pointer', () => {
    // A 1200 px grid: a column pitch of (1200 + 14) / 12 and a row pitch of 74.
    const start: Geometry = { col: 2, row: 1, width: 4, height: 'small' };

    it('moves by whole cells and stays in the grid', () => {
        expect(moved(start, 101, 74, 1200)).toEqual({
            ...start,
            col: 3,
            row: 2,
        });
        expect(moved(start, 100000, -100000, 1200)).toEqual({
            ...start,
            col: 8,
            row: 0,
        });
    });

    it('resizes to the nearest width and height presets within the limits', () => {
        const wide = resized(start, 2 * 101.17, 0, 1200);

        expect(wide.width).toBe(6);
        expect(resized(start, 0, 4 * 74, 1200).height).toBe('medium');
        expect(resized(start, 0, 0, 1200)).toEqual(start);
        expect(resized(start, 50000, 0, 1200).width).toBe(12);
    });
});

describe('place', () => {
    it('puts one item where asked and settles the rest around it', () => {
        const layout: Layout = {
            a: { col: 0, row: 0, width: 6, height: 'small' },
            b: { col: 6, row: 0, width: 6, height: 'small' },
        };

        expect(
            place(layout, 'a', { col: 6, row: 0, width: 6, height: 'small' }),
        ).toEqual({
            a: { col: 6, row: 0, width: 6, height: 'small' },
            b: { col: 6, row: 2, width: 6, height: 'small' },
        });
    });
});

describe('sameLayout', () => {
    it('compares ids and geometry', () => {
        const a: Layout = { x: { col: 0, row: 0, width: 3, height: 'small' } };

        expect(sameLayout(a, { x: { ...a.x } })).toBe(true);
        expect(sameLayout(a, { x: { ...a.x, col: 3 } })).toBe(false);
        expect(sameLayout(a, {})).toBe(false);
    });
});

describe('keyboard steps', () => {
    const g: Geometry = { col: 4, row: 4, width: 4, height: 'medium' };

    it('moves one column or one Small row at a time and stays in the grid', () => {
        expect(step(g, 'left')).toEqual({ ...g, col: 3 });
        expect(step(g, 'right')).toEqual({ ...g, col: 5 });
        expect(step(g, 'up')).toEqual({ ...g, row: 2 });
        expect(step(g, 'down')).toEqual({ ...g, row: 6 });
        expect(step({ ...g, col: 0, row: 0 }, 'left')).toEqual({
            ...g,
            col: 0,
            row: 0,
        });
        expect(step({ ...g, col: 8 }, 'right')).toEqual({ ...g, col: 8 });
    });

    it('steps the size through the presets within the limits', () => {
        const limits = {
            minWidth: 3,
            maxWidth: 6,
            minHeight: 'small' as const,
            maxHeight: 'medium' as const,
        };

        expect(resizeStep(g, 'right', limits).width).toBe(6);
        expect(resizeStep({ ...g, width: 6 }, 'right', limits).width).toBe(6);
        expect(resizeStep(g, 'left', limits).width).toBe(3);
        expect(resizeStep(g, 'down', limits).height).toBe('medium');
        expect(resizeStep(g, 'up', limits).height).toBe('small');
        expect(canWiden({ ...g, width: 6 }, limits)).toBe(false);
        expect(canWiden(g, limits)).toBe(true);
        expect(canNarrow({ ...g, width: 3 }, limits)).toBe(false);
    });
});

describe('describeMove', () => {
    const names = {
        a: 'Revenue overview',
        b: 'Revenue vs Expense',
        c: 'Project status',
    };

    it('names the position, the neighbour and what was pushed down', () => {
        const before: Layout = {
            a: { col: 0, row: 0, width: 6, height: 'small' },
            b: { col: 6, row: 4, width: 3, height: 'small' },
            c: { col: 6, row: 0, width: 3, height: 'small' },
        };
        const after = place(before, 'b', {
            col: 6,
            row: 0,
            width: 3,
            height: 'small',
        });

        expect(describeMove(after, before, 'b', names)).toBe(
            'Revenue vs Expense, row 1, column 7, right of Revenue overview; Project status moved down',
        );
    });
});

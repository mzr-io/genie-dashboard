import { describe, expect, it } from 'vitest';
import {
    clamp,
    moved,
    place,
    resized,
    resolve,
    sameLayout,
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

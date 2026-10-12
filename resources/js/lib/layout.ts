import { HEIGHT_UNITS } from '@/lib/dashboards';
import type { HeightPreset } from '@/lib/dashboards';

// The dashboard grid's rules in the browser (Story 4.11; AD-14): the same settling the server's `Platform\Layout\Grid` applies when a layout is saved,
// so the arrangement a person sees while dragging is the one the server will keep. Both run the cases of `tests/Fixtures/layout-resolve.json`.
export type Geometry = {
    col: number;
    row: number;
    width: number;
    height: HeightPreset;
};

export type Layout = Record<string, Geometry>;

export const COLUMNS = 12;
export const WIDTHS = [3, 4, 6, 8, 12] as const;
export const HEIGHTS: HeightPreset[] = ['small', 'medium', 'large'];
// The grid's rhythm in px: a 60 px row and the 14 px gap (UX-DR-149).
export const ROW_PX = 60;
export const GAP_PX = 14;

export type Limits = {
    minWidth: number;
    maxWidth: number;
    minHeight: HeightPreset;
    maxHeight: HeightPreset;
};

export const NO_LIMITS: Limits = {
    minWidth: 3,
    maxWidth: 12,
    minHeight: 'small',
    maxHeight: 'large',
};

const units = (height: HeightPreset): number => HEIGHT_UNITS[height] ?? 2;

// A width snaps to the nearest preset within the Block's limits (the smaller on a tie); a height likewise; the column keeps the rectangle in the grid.
export function clamp(
    geometry: Geometry,
    limits: Limits = NO_LIMITS,
): Geometry {
    const allowed = WIDTHS.filter(
        (w) => w >= limits.minWidth && w <= limits.maxWidth,
    );
    const widths = allowed.length > 0 ? allowed : [...WIDTHS];
    let width: number = widths[0];

    for (const candidate of widths) {
        if (
            Math.abs(candidate - geometry.width) <
            Math.abs(width - geometry.width)
        ) {
            width = candidate;
        }
    }

    const from = Math.max(0, HEIGHTS.indexOf(limits.minHeight));
    const to = Math.max(from, HEIGHTS.indexOf(limits.maxHeight));
    const at = Math.min(
        Math.max(Math.max(0, HEIGHTS.indexOf(geometry.height)), from),
        to,
    );

    return {
        col: Math.min(Math.max(geometry.col, 0), COLUMNS - width),
        row: Math.max(geometry.row, 0),
        width,
        height: HEIGHTS[at],
    };
}

function overlaps(placed: Geometry[], g: Geometry, row: number): boolean {
    const u = units(g.height);

    return placed.some(
        (p) =>
            g.col < p.col + p.width &&
            p.col < g.col + g.width &&
            row < p.row + units(p.height) &&
            p.row < row + u,
    );
}

// Moved items keep their places (one that lands on an earlier moved item is pushed down); every other item, in reading order, stays unless something
// placed before it is in the way, and then goes down just far enough. Nothing moves up and no id is added or dropped.
export function resolve(moved: Layout, others: Layout): Layout {
    const rest = Object.entries(others).sort(
        ([, a], [, b]) => a.row - b.row || a.col - b.col,
    );
    const queue = [...Object.entries(moved), ...rest];
    const placed: Geometry[] = [];
    const out: Layout = {};

    for (const [id, geometry] of queue) {
        let row = geometry.row;

        while (overlaps(placed, geometry, row)) {
            row += 1;
        }

        const settled = { ...geometry, row };

        placed.push(settled);
        out[id] = settled;
    }

    return out;
}

// One item changed: it keeps its new place and everything else settles around it.
export function place(
    layout: Layout,
    id: string,
    geometry: Geometry,
    limits: Limits = NO_LIMITS,
): Layout {
    const { [id]: _removed, ...others } = layout;

    return resolve({ [id]: clamp(geometry, limits) }, others);
}

export const sameGeometry = (a: Geometry, b: Geometry): boolean =>
    a.col === b.col &&
    a.row === b.row &&
    a.width === b.width &&
    a.height === b.height;

export const sameLayout = (a: Layout, b: Layout): boolean => {
    const keys = Object.keys(a);

    return (
        keys.length === Object.keys(b).length &&
        keys.every(
            (key) => b[key] !== undefined && sameGeometry(a[key], b[key]),
        )
    );
};

// The cell under a pointer offset: the grid's column pitch is (width + gap) / 12 and its row pitch is a row plus the gap.
export function pitch(gridWidth: number): { x: number; y: number } {
    return { x: (gridWidth + GAP_PX) / COLUMNS, y: ROW_PX + GAP_PX };
}

// A resize by a pointer offset snaps to the nearest width preset and height preset within the limits.
export function resized(
    start: Geometry,
    dx: number,
    dy: number,
    gridWidth: number,
    limits: Limits = NO_LIMITS,
): Geometry {
    const p = pitch(gridWidth);
    const columns = start.width + Math.round(dx / p.x);
    const rows = units(start.height) + Math.round(dy / p.y);

    // The height preset whose size in rows is nearest to the requested one.
    let height: HeightPreset = HEIGHTS[0];

    for (const candidate of HEIGHTS) {
        if (
            Math.abs(units(candidate) - rows) < Math.abs(units(height) - rows)
        ) {
            height = candidate;
        }
    }

    return clamp({ ...start, width: columns, height }, limits);
}

export function moved(
    start: Geometry,
    dx: number,
    dy: number,
    gridWidth: number,
): Geometry {
    const p = pitch(gridWidth);

    return {
        ...start,
        col: Math.min(
            Math.max(start.col + Math.round(dx / p.x), 0),
            COLUMNS - start.width,
        ),
        row: Math.max(start.row + Math.round(dy / p.y), 0),
    };
}

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

// ---- Keyboard and menu steps (Story 4.12) ---------------------------------------------------------------------------------------------------------

// One arrow press moves a Block one column or one Small row (two 60 px units); a menu step is the same.
export const ROW_STEP = 2;

export type Direction = 'left' | 'right' | 'up' | 'down';

export function step(geometry: Geometry, direction: Direction): Geometry {
    switch (direction) {
        case 'left':
            return clamp({ ...geometry, col: geometry.col - 1 });
        case 'right':
            return clamp({ ...geometry, col: geometry.col + 1 });
        case 'up':
            return { ...geometry, row: Math.max(0, geometry.row - ROW_STEP) };
        default:
            return { ...geometry, row: geometry.row + ROW_STEP };
    }
}

// Shift plus an arrow: left and right step the width through the presets, up and down the height; never past the Block's limits.
export function resizeStep(
    geometry: Geometry,
    direction: Direction,
    limits: Limits = NO_LIMITS,
): Geometry {
    if (direction === 'left' || direction === 'right') {
        const widths = WIDTHS.filter(
            (w) => w >= limits.minWidth && w <= limits.maxWidth,
        );
        const at = widths.indexOf(geometry.width as (typeof WIDTHS)[number]);
        const next =
            direction === 'right'
                ? widths[Math.min(widths.length - 1, at + 1)]
                : widths[Math.max(0, at - 1)];

        return clamp({ ...geometry, width: next ?? geometry.width }, limits);
    }

    const from = HEIGHTS.indexOf(limits.minHeight);
    const to = HEIGHTS.indexOf(limits.maxHeight);
    const at = HEIGHTS.indexOf(geometry.height);
    const next =
        direction === 'down' ? Math.min(to, at + 1) : Math.max(from, at - 1);

    return clamp({ ...geometry, height: HEIGHTS[next] }, limits);
}

// Whether a step in a direction changes the width at all (a menu item disables "Make wider" at the largest allowed size).
export function canWiden(
    geometry: Geometry,
    limits: Limits = NO_LIMITS,
): boolean {
    return resizeStep(geometry, 'right', limits).width !== geometry.width;
}

export function canNarrow(
    geometry: Geometry,
    limits: Limits = NO_LIMITS,
): boolean {
    return resizeStep(geometry, 'left', limits).width !== geometry.width;
}

// What a screen reader hears when a move lands: where the Block is (row and column among the layout's rows) and what it is next to, then the
// Blocks it pushed down. "Revenue vs Expense, row 3, column 7, right of Revenue overview; Project status moved down".
export function describeMove(
    after: Layout,
    before: Layout,
    id: string,
    names: Record<string, string>,
): string {
    const me = after[id];

    if (me === undefined) {
        return '';
    }

    const rows = Array.from(
        new Set(Object.values(after).map((g) => g.row)),
    ).sort((a, b) => a - b);
    const where = `row ${rows.indexOf(me.row) + 1}, column ${me.col + 1}`;
    const others = Object.entries(after).filter(([other]) => other !== id);
    const sameBand = (g: Geometry) =>
        g.row < me.row + units(me.height) && me.row < g.row + units(g.height);
    const left = others
        .filter(([, g]) => sameBand(g) && g.col + g.width <= me.col)
        .sort(([, a], [, b]) => b.col - a.col)[0];
    const right = others
        .filter(([, g]) => sameBand(g) && g.col >= me.col + me.width)
        .sort(([, a], [, b]) => a.col - b.col)[0];
    const above = others
        .filter(
            ([, g]) =>
                g.row + units(g.height) <= me.row &&
                g.col < me.col + me.width &&
                me.col < g.col + g.width,
        )
        .sort(([, a], [, b]) => b.row - a.row)[0];
    const below = others
        .filter(
            ([, g]) =>
                g.row >= me.row + units(me.height) &&
                g.col < me.col + me.width &&
                me.col < g.col + g.width,
        )
        .sort(([, a], [, b]) => a.row - b.row)[0];

    const relation = left
        ? `right of ${names[left[0]] ?? 'another block'}`
        : right
          ? `left of ${names[right[0]] ?? 'another block'}`
          : above
            ? `below ${names[above[0]] ?? 'another block'}`
            : below
              ? `above ${names[below[0]] ?? 'another block'}`
              : '';
    const pushed = Object.keys(after)
        .filter(
            (other) =>
                other !== id &&
                before[other] !== undefined &&
                after[other].row > before[other].row,
        )
        .map((other) => names[other] ?? 'Another block');

    return [
        `${names[id] ?? 'Block'}, ${where}${relation === '' ? '' : `, ${relation}`}`,
        pushed.length === 0 ? '' : `${pushed.join(', ')} moved down`,
    ]
        .filter((part) => part !== '')
        .join('; ');
}

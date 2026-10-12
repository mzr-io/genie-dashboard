// Plain calendar days for the Date Range popover (Story 4.15): `YYYY-MM-DD` strings, no time and no time zone, so a day never shifts. The server
// resolves every preset in the Workspace timezone; the calendar only picks the dates of a Custom range.

export type DateRangeValue = {
    range: string;
    from: string | null;
    to: string | null;
};

// The presets the popover lists, in order, and every preset a Block Period Selector may offer (the catalogue, FR-35).
export const DASHBOARD_PRESETS = [
    'today',
    'this_week',
    'this_month',
    'current_year',
] as const;

export const ALL_PRESETS = [
    'today',
    'yesterday',
    'this_week',
    'last_week',
    'this_month',
    'last_month',
    'this_quarter',
    'last_quarter',
    'current_year',
    'last_year',
    'last_7_days',
    'last_30_days',
    'last_90_days',
] as const;

const parts = (iso: string): [number, number, number] => {
    const [y, m, d] = iso.split('-').map(Number);

    return [y, m, d];
};

const pad = (n: number): string => String(n).padStart(2, '0');

export function toIso(year: number, month1: number, day: number): string {
    const date = new Date(Date.UTC(year, month1 - 1, day));

    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

export function addDays(iso: string, days: number): string {
    const [y, m, d] = parts(iso);

    return toIso(y, m, d + days);
}

// One month earlier or later, keeping the day where the month has it and clamping to the month's last day otherwise.
export function addMonths(iso: string, months: number): string {
    const [y, m, d] = parts(iso);
    const first = new Date(Date.UTC(y, m - 1 + months, 1));
    const last = new Date(
        Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0),
    ).getUTCDate();

    return toIso(
        first.getUTCFullYear(),
        first.getUTCMonth() + 1,
        Math.min(d, last),
    );
}

// Monday is the first day of the week, as everywhere in the product.
export function weekday(iso: string): number {
    const [y, m, d] = parts(iso);

    return (new Date(Date.UTC(y, m - 1, d)).getUTCDay() + 6) % 7;
}

export function monthKey(iso: string): string {
    return iso.slice(0, 7);
}

// The weeks of one month as rows of seven cells: a day, or null for the days of the neighbouring months.
export function monthWeeks(year: number, month1: number): (string | null)[][] {
    const first = toIso(year, month1, 1);
    const count = new Date(Date.UTC(year, month1, 0)).getUTCDate();
    const cells: (string | null)[] = [
        ...Array.from({ length: weekday(first) }, () => null),
        ...Array.from({ length: count }, (_, i) => toIso(year, month1, i + 1)),
    ];

    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return Array.from({ length: cells.length / 7 }, (_, i) =>
        cells.slice(i * 7, i * 7 + 7),
    );
}

const monthDay = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const monthYear = new Intl.DateTimeFormat('en-US', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const at = (iso: string): Date => {
    const [y, m, d] = parts(iso);

    return new Date(Date.UTC(y, m - 1, d));
};

export const shortDay = (iso: string): string => monthDay.format(at(iso));
export const monthTitle = (iso: string): string => monthYear.format(at(iso));

export function rangeText(from: string, to: string): string {
    return from === to ? shortDay(from) : `${shortDay(from)} – ${shortDay(to)}`;
}

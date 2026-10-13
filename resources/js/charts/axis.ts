// The labels of a chart's X axis (Story 5.10): an ISO date, a month (`2026-01`) or a date-time, written by the X slot's pattern in the viewer's
// locale (`MMM` is "Jan"); anything else is the label as it came. Dates are read as the calendar day they name, never shifted by a time zone.

const ISO = /^(\d{4})-(\d{2})(?:-(\d{2}))?(?:[T ].*)?$/;

const OPTIONS: Record<string, Intl.DateTimeFormatOptions> = {
    MMM: { month: 'short' },
    'MMM yyyy': { month: 'short', year: 'numeric' },
    'MMM d': { month: 'short', day: 'numeric' },
    short: { dateStyle: 'short' },
    medium: { dateStyle: 'medium' },
    long: { dateStyle: 'long' },
    full: { dateStyle: 'full' },
};

export function axisLabel(
    value: string,
    locale: string,
    pattern: string | null = null,
): string {
    const match = ISO.exec(value.trim());

    if (match === null) {
        return value;
    }

    const [, year, month, day] = match;
    const date = new Date(
        Date.UTC(Number(year), Number(month) - 1, Number(day ?? 1)),
    );

    if (
        Number.isNaN(date.getTime()) ||
        Number(month) < 1 ||
        Number(month) > 12
    ) {
        return value;
    }

    const options =
        (pattern !== null ? OPTIONS[pattern] : undefined) ??
        (day === undefined ? OPTIONS['MMM yyyy'] : OPTIONS.medium);

    try {
        return new Intl.DateTimeFormat(locale, {
            ...options,
            timeZone: 'UTC',
        }).format(date);
    } catch {
        return value;
    }
}

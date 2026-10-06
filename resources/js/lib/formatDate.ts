const MONTHS = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    timeZone: 'UTC',
});

// An unparsable date gives ""; otherwise "2 Oct 2026": day, abbreviated month, year, always in UTC so the saved date never shifts.
export function formatDay(value: string | Date): string {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getUTCDate()} ${MONTHS.format(date)} ${date.getUTCFullYear()}`;
}

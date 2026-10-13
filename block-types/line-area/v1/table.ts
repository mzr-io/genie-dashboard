import { formatCurrency, formatDate, formatNumber } from '@/lib/format';
import type { LineAreaFormat, LineAreaPayload } from './payload';

// The chart as a table (Story 5.9): the same formats the plot uses, so a reader gets the exact values. `View as table` is built from this on the
// displayed payload; the Expanded view formats the cells of its server rows with the same `cell` function.

function places(format: LineAreaFormat): Intl.NumberFormatOptions {
    return format.decimalPlaces === null
        ? {}
        : {
              minimumFractionDigits: format.decimalPlaces,
              maximumFractionDigits: format.decimalPlaces,
          };
}

export function measure(
    value: string,
    format: LineAreaFormat,
    locale: string,
): string {
    const grouping = { useGrouping: format.thousandsSeparator };

    if (format.kind === 'currency' && format.currencyCode !== null) {
        return formatCurrency(
            value,
            format.currencyCode,
            {
                ...places(format),
                ...grouping,
                currencyDisplay: format.currencyDisplay,
            },
            locale,
        );
    }

    return formatNumber(
        value,
        {
            ...places(format),
            ...grouping,
            style: format.kind === 'percent' ? 'percent' : 'decimal',
        },
        locale,
    );
}

export function axis(value: string, locale: string): string {
    return /^\d{4}-\d{2}-\d{2}/.test(value)
        ? formatDate(value, { dateStyle: 'medium' }, locale) || value
        : value;
}

const FALLBACK: LineAreaFormat = {
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
};

// A cell of a rows page: the axis as a date, a series as a measure, anything else as it came.
export function cell(
    key: string,
    value: string | boolean | null,
    payload: Pick<LineAreaPayload, 'format'>,
    locale: string,
): string {
    if (value === null) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (key === 'x') {
        return axis(value, locale);
    }

    return key.startsWith('series_')
        ? measure(value, payload.format ?? FALLBACK, locale) || value
        : value;
}

export type TableModel = {
    columns: { key: string; label: string }[];
    rows: string[][];
};

// The table of the displayed payload: one column for the axis and one for each series, every cell formatted as the chart formats it.
export function tableOf(payload: LineAreaPayload, locale: string): TableModel {
    const x = payload.x ?? [];
    const series = payload.series ?? [];
    const format = payload.format ?? FALLBACK;

    return {
        columns: [
            { key: 'x', label: payload.xLabel ?? 'X axis' },
            ...series.map((s) => ({ key: s.key, label: s.name })),
        ],
        rows: x.map((value, at) => [
            axis(value, locale),
            ...series.map((s) => {
                const point = s.values[at] ?? null;

                return point === null
                    ? '—'
                    : measure(point, format, locale) || point;
            }),
        ]),
    };
}

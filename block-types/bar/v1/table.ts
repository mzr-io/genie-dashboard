import { formatCurrency, formatNumber } from '@/lib/format';
import type { BarFormat, BarPayload } from './payload';

// The bar chart as a table (Story 5.9): the category and one column for each series, in the chart's format.
const FALLBACK: BarFormat = {
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
};

export function measure(
    value: string,
    format: BarFormat,
    locale: string,
): string {
    const places =
        format.decimalPlaces === null
            ? {}
            : {
                  minimumFractionDigits: format.decimalPlaces,
                  maximumFractionDigits: format.decimalPlaces,
              };
    const grouping = { useGrouping: format.thousandsSeparator };

    if (format.kind === 'currency' && format.currencyCode !== null) {
        return formatCurrency(
            value,
            format.currencyCode,
            { ...places, ...grouping, currencyDisplay: format.currencyDisplay },
            locale,
        );
    }

    return formatNumber(
        value,
        {
            ...places,
            ...grouping,
            style: format.kind === 'percent' ? 'percent' : 'decimal',
        },
        locale,
    );
}

export function cell(
    key: string,
    value: string | boolean | null,
    payload: Pick<BarPayload, 'format'>,
    locale: string,
): string {
    if (value === null) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    return key.startsWith('values_')
        ? measure(value, payload.format ?? FALLBACK, locale) || value
        : value;
}

export function tableOf(
    payload: BarPayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    const categories = payload.categories ?? [];
    const series = payload.series ?? [];
    const format = payload.format ?? FALLBACK;

    return {
        columns: [
            { key: 'category', label: payload.categoryLabel ?? 'Category' },
            ...series.map((s) => ({ key: s.key, label: s.name })),
        ],
        rows: categories.map((name, at) => [
            name,
            ...series.map((s) => {
                const point = s.values[at] ?? null;

                return point === null
                    ? '—'
                    : measure(point, format, locale) || point;
            }),
        ]),
    };
}

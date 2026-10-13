import { axisLabel } from '@/charts/axis';
import { compareDecimal } from '@/charts/model';
import { formatCurrency, formatNumber } from '@/lib/format';
import type { TableColumn, TableFormat, TablePayload } from './payload';

// Cells of a table (Stories 5.9 and 5.12): a decimal is formatted from its string by the column's format, a date by its pattern, anything else is
// the text it came as. A missing value is "—", never 0.
const DECIMAL = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
const ISO = /^\d{4}-\d{2}(-\d{2})?([T ].*)?$/;

export function isDecimal(value: string): boolean {
    return DECIMAL.test(value.trim());
}

export function cellText(
    value: string | null,
    format: TableFormat | null,
    locale: string,
): string {
    if (value === null) {
        return '—';
    }

    if (format === null) {
        return value;
    }

    if (format.kind === 'date') {
        return ISO.test(value)
            ? axisLabel(value, locale, format.datePattern)
            : value;
    }

    if (!isDecimal(value)) {
        return value;
    }

    const places =
        format.decimalPlaces === null
            ? {}
            : {
                  minimumFractionDigits: format.decimalPlaces,
                  maximumFractionDigits: format.decimalPlaces,
              };
    const grouping = { useGrouping: format.thousandsSeparator };
    let text: string;

    if (format.kind === 'currency' && format.currencyCode !== null) {
        text = formatCurrency(
            value,
            format.currencyCode,
            { ...places, ...grouping, currencyDisplay: format.currencyDisplay },
            locale,
        );
    } else if (format.kind === 'compact') {
        text = formatNumber(
            value,
            { ...grouping, notation: 'compact', ...places },
            locale,
        );
    } else {
        text = formatNumber(
            value,
            {
                ...places,
                ...grouping,
                style: format.kind === 'percent' ? 'percent' : 'decimal',
            },
            locale,
        );
    }

    return text === ''
        ? value
        : format.unitsSuffix === null
          ? text
          : `${text} ${format.unitsSuffix}`;
}

// The order of two cells of a column: decimals by value, exactly; missing last; text by the locale.
export function compareCells(
    a: string | null,
    b: string | null,
    locale: string,
): number {
    if (a === null || b === null) {
        return a === b ? 0 : a === null ? 1 : -1;
    }

    if (isDecimal(a) && isDecimal(b)) {
        return compareDecimal(a, b) ?? 0;
    }

    return a.localeCompare(b, locale, { numeric: true });
}

export type TableModel = {
    columns: { key: string; label: string }[];
    rows: string[][];
};

// The table of the displayed payload: the header of each column and every cell as the Block shows it.
export function tableOf(payload: TablePayload, locale: string): TableModel {
    const columns = payload.columns ?? [];

    return {
        columns: columns.map((c) => ({ key: c.key, label: c.header })),
        rows: (payload.rows ?? []).map((row) =>
            row.cells.map((cell, at) =>
                cellText(cell, columns[at]?.format ?? null, locale),
            ),
        ),
    };
}

export function cell(
    key: string,
    value: string | boolean | null,
    payload: Pick<TablePayload, 'columns'>,
    locale: string,
): string {
    const column: TableColumn | undefined = payload.columns?.find(
        (c) => c.key === key,
    );

    return cellText(
        value === null
            ? null
            : typeof value === 'boolean'
              ? String(value)
              : value,
        column?.format ?? null,
        locale,
    );
}

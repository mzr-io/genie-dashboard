import { cellText } from '../../table/v1/table';
import type { ListPayload } from './payload';

// The list as a table (Story 5.9): one row per item, every text as the list shows it.
const COLUMNS = [
    { key: 'item_title', label: 'Item' },
    { key: 'item_subtitle', label: 'Subtitle' },
    { key: 'item_avatar', label: 'Name' },
    { key: 'item_value', label: 'Value' },
    { key: 'item_meta', label: 'Detail' },
    { key: 'item_status', label: 'Status' },
];

export function cell(
    key: string,
    value: string | boolean | null,
    payload: Pick<ListPayload, 'valueFormat' | 'metaFormat'>,
    locale: string,
): string {
    const text =
        value === null
            ? null
            : typeof value === 'boolean'
              ? String(value)
              : value;
    const format =
        key === 'item_value'
            ? payload.valueFormat
            : key === 'item_meta'
              ? payload.metaFormat
              : null;

    return cellText(text, format, locale);
}

export function tableOf(
    payload: ListPayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    const rows = payload.rows ?? [];
    const used = COLUMNS.filter(
        (column) =>
            column.key === 'item_title' ||
            rows.some((row) => rowText(row, column.key) !== null),
    );

    return {
        columns: used,
        rows: rows.map((row) =>
            used.map((column) =>
                cell(column.key, rowText(row, column.key), payload, locale),
            ),
        ),
    };
}

function rowText(row: ListPayload['rows'][number], key: string): string | null {
    switch (key) {
        case 'item_title':
            return row.title;
        case 'item_subtitle':
            return row.subtitle;
        case 'item_avatar':
            return row.avatar?.initials ?? null;
        case 'item_value':
            return row.value;
        case 'item_meta':
            return row.meta;
        default:
            return row.status;
    }
}

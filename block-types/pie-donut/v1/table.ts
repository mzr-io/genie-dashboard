import { measure } from '../../bar/v1/table';
import type { PiePayload } from './payload';

// The pie as a table (Story 5.9): each slice with its value and its share of the positive total.
export function cell(
    key: string,
    value: string | boolean | null,
    payload: Pick<PiePayload, 'format'>,
    locale: string,
): string {
    if (value === null) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    return key === 'pie_value' && payload.format !== undefined
        ? measure(value, payload.format, locale) || value
        : value;
}

export function tableOf(
    payload: PiePayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    const names = payload.categories ?? [];
    const values = payload.values ?? [];

    return {
        columns: [
            { key: 'pie_category', label: payload.categoryLabel ?? 'Category' },
            { key: 'pie_value', label: payload.valueLabel ?? 'Value' },
        ],
        rows: names.map((name, at) => [
            name,
            values[at] === null || values[at] === undefined
                ? '—'
                : payload.format
                  ? measure(values[at] as string, payload.format, locale) ||
                    (values[at] as string)
                  : (values[at] as string),
        ]),
    };
}

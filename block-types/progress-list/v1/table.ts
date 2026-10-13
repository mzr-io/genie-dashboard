import { formatNumber } from '@/lib/format';
import type { ProgressListPayload } from './payload';

// The progress list as a table (Story 5.9): each item with its caption and its percent.
export function percentText(percent: string | null, locale: string): string {
    if (percent === null) {
        return '—';
    }

    const text = formatNumber(percent, { style: 'decimal' }, locale);

    return `${text === '' ? percent : text}%`;
}

// What a screen reader is told of a row: "Website redesign, 12 of 16 tasks, 75%".
export function spoken(
    title: string | null,
    caption: string | null,
    percent: string | null,
    locale: string,
): string {
    const said = caption?.replace(/(\d[\d.,]*)\s*\/\s*(\d[\d.,]*)/, '$1 of $2');

    return [title ?? '—', said, percentText(percent, locale)]
        .filter((part) => part !== null && part !== undefined && part !== '')
        .join(', ');
}

export function cell(
    key: string,
    value: string | boolean | null,
    _payload: unknown,
    locale: string,
): string {
    if (value === null) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return String(value);
    }

    return key === 'progress_value' || key === 'progress_total'
        ? formatNumber(value, { style: 'decimal' }, locale) || value
        : value;
}

export function tableOf(
    payload: ProgressListPayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    return {
        columns: [
            { key: 'item_title', label: 'Item' },
            { key: 'item_caption', label: 'Caption' },
            { key: 'progress_value', label: 'Progress' },
        ],
        rows: (payload.rows ?? []).map((row) => [
            row.title ?? '—',
            row.caption ?? '—',
            percentText(row.percent, locale),
        ]),
    };
}

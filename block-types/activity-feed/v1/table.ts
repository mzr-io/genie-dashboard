import { formatDate } from '@/lib/format';
import type { ActivityPayload } from './payload';

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

// "8 min. ago" in the viewer's locale; a time still to come is the date; one that is not a date is "—".
export function ago(
    timestamp: string | null,
    now: Date,
    locale: string,
): string {
    if (timestamp === null) {
        return '—';
    }

    const at = new Date(
        DATE_ONLY.test(timestamp) ? `${timestamp}T00:00:00Z` : timestamp,
    );

    if (Number.isNaN(at.getTime())) {
        return '—';
    }

    if (at.getTime() > now.getTime()) {
        return (
            formatDate(
                timestamp,
                DATE_ONLY.test(timestamp)
                    ? { dateStyle: 'medium' }
                    : { dateStyle: 'medium', timeStyle: 'short' },
                locale,
            ) || '—'
        );
    }

    const seconds = Math.round((at.getTime() - now.getTime()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const [unit, size] = steps.find(([, s]) => Math.abs(seconds) >= s) ?? [
        'second',
        1,
    ];

    return new Intl.RelativeTimeFormat(locale, {
        numeric: 'auto',
        style: 'short',
    }).format(Math.trunc(seconds / size), unit);
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

    return key === 'timestamp'
        ? formatDate(
              value,
              { dateStyle: 'medium', timeStyle: 'short' },
              locale,
          ) || '—'
        : value;
}

// The feed as a table (Story 5.9): when, who, what.
export function tableOf(
    payload: ActivityPayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    return {
        columns: [
            { key: 'timestamp', label: 'When' },
            { key: 'actor', label: 'Actor' },
            { key: 'action', label: 'Action' },
            { key: 'object', label: 'Object' },
        ],
        rows: (payload.rows ?? []).map((row) => [
            cell('timestamp', row.timestamp, payload, locale),
            row.actor ?? '—',
            row.action ?? '—',
            row.object ?? '—',
        ]),
    };
}

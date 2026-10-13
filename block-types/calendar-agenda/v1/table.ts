import { formatDate } from '@/lib/format';
import type { AgendaPayload, AgendaRow } from './payload';

// What a screen reader is told of an agenda row: "09:30, Product sync, Meeting room 2A, 45 min".
export function spoken(row: AgendaRow): string {
    return [
        row.time,
        row.title ?? '—',
        row.subtitle,
        row.duration === null ? null : `${row.duration} min`,
        row.category,
    ]
        .filter((part) => part !== null && part !== '')
        .join(', ');
}

// "Wednesday 22 May" for a calendar day: the day stays the day it is written as, in every zone.
export function dayName(date: string, locale: string): string {
    return (
        formatDate(
            date,
            { weekday: 'long', day: 'numeric', month: 'long' },
            locale,
        ) || date
    );
}

// The seven days (Monday to Sunday) of the week that holds `day`, as calendar days.
export function weekOf(day: string): string[] {
    const at = new Date(`${day}T00:00:00Z`);
    const offset = (at.getUTCDay() + 6) % 7;

    return Array.from({ length: 7 }, (_, n) => {
        const d = new Date(at.getTime() + (n - offset) * 86400000);

        return d.toISOString().slice(0, 10);
    });
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

    return key === 'event_date' ? dayName(value, locale) : value;
}

export function tableOf(
    payload: AgendaPayload,
    locale: string,
): { columns: { key: string; label: string }[]; rows: string[][] } {
    return {
        columns: [
            { key: 'event_date', label: 'Date' },
            { key: 'start_time', label: 'Time' },
            { key: 'event_title', label: 'Event' },
            { key: 'event_subtitle', label: 'Where' },
            { key: 'duration', label: 'Minutes' },
            { key: 'category', label: 'Category' },
        ],
        rows: (payload.rows ?? []).map((row) => [
            row.date === null ? '—' : dayName(row.date, locale),
            row.time ?? '—',
            row.title ?? '—',
            row.subtitle ?? '—',
            row.duration ?? '—',
            row.category ?? '—',
        ]),
    };
}

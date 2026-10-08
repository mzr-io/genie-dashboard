import { shallowRef } from 'vue';
import { DEFAULT_LOCALE } from '@/lib/i18n';
import { byteUnitLabels } from '@/locales/labels';

// The one place numbers, currency and dates are formatted for display (Story 1.18). It uses `Intl` with the
// signed-in person's locale and time zone, which the app shell keeps current from the saved profile
// (`configureFormatting`). A person with no saved choice gets the catalogue locale and the browser's own time
// zone. The state is reactive, so a template that formats re-renders when the profile is saved.
//
// Numbers are lossless (NFR): a decimal string is handed to `Intl` as the string it is, never through
// `Number`, and no digit is rounded away unless the caller asks for it with `options`.

export type Formatting = { locale: string; timeZone: string | undefined };

const state = shallowRef<Formatting>({
    locale: DEFAULT_LOCALE,
    timeZone: undefined,
});

function usableLocale(locale: string | null | undefined): string {
    if (!locale) {
        return DEFAULT_LOCALE;
    }

    try {
        return Intl.getCanonicalLocales(locale)[0] ?? DEFAULT_LOCALE;
    } catch {
        return DEFAULT_LOCALE;
    }
}

function usableTimeZone(
    timeZone: string | null | undefined,
): string | undefined {
    if (!timeZone) {
        return undefined;
    }

    try {
        new Intl.DateTimeFormat(DEFAULT_LOCALE, { timeZone });

        return timeZone;
    } catch {
        return undefined;
    }
}

// Applies the person's saved locale and time zone; an unknown value falls back rather than throwing.
export function configureFormatting(profile: {
    locale?: string | null;
    timeZone?: string | null;
}): void {
    const next: Formatting = {
        locale: usableLocale(profile.locale),
        timeZone: usableTimeZone(profile.timeZone),
    };

    if (
        next.locale !== state.value.locale ||
        next.timeZone !== state.value.timeZone
    ) {
        state.value = next;
    }
}

export function currentFormatting(): Formatting {
    return state.value;
}

// A plain decimal, with an optional sign and exponent: the only strings that are formatted.
const DECIMAL = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;

function readable(value: string | number | bigint): boolean {
    if (typeof value === 'string') {
        return DECIMAL.test(value.trim());
    }

    return typeof value === 'bigint' || Number.isFinite(value);
}

// Enough fraction digits that none of a decimal string is dropped.
const LOSSLESS_DIGITS = 100;

// An empty string for something that is not a number, so a bad value never shows up as text.
export function formatNumber(
    value: string | number | bigint,
    options: Intl.NumberFormatOptions = {},
): string {
    if (!readable(value)) {
        return '';
    }

    const { locale } = state.value;
    const input = typeof value === 'string' ? value.trim() : value;

    return new Intl.NumberFormat(locale, {
        maximumFractionDigits: LOSSLESS_DIGITS,
        ...options,
    }).format(input as Intl.StringNumericLiteral);
}

export function formatCurrency(
    value: string | number | bigint,
    currency: string,
    options: Intl.NumberFormatOptions = {},
): string {
    try {
        return formatNumber(value, { style: 'currency', currency, ...options });
    } catch {
        // An unknown currency code is not guessed at.
        return '';
    }
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

function toDate(value: string | number | Date): Date | null {
    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

// "" for an unparsable date. The day is the one in the person's time zone.
export function formatDate(
    value: string | number | Date,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string {
    const date = toDate(value);

    if (date === null) {
        return '';
    }

    // A date without a time ("2026-10-07") names a calendar day, not an instant: it stays that day everywhere.
    const dayOnly = typeof value === 'string' && DATE_ONLY.test(value.trim());
    const { locale } = state.value;
    const timeZone = dayOnly ? 'UTC' : state.value.timeZone;

    return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(
        date,
    );
}

export function formatDateTime(
    value: string | number | Date,
    options: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    },
): string {
    return formatDate(value, options);
}

// A size for people (Story 2.6): 1024-based steps, one fraction digit at most, the unit from `labels.ts`.
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return '';
    }

    let value = bytes;
    let unit = 0;

    // Round first, then carry: 1,048,575 bytes is "1 MB", never "1,024 KB".
    const rounded = (n: number, u: number): number =>
        u === 0 ? Math.round(n) : Math.round(n * 10) / 10;

    while (rounded(value, unit) >= 1024 && unit < byteUnitLabels.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const shown = formatNumber(
        unit === 0 ? value : Math.round(value * 10) / 10,
        {
            maximumFractionDigits: unit === 0 ? 0 : 1,
        },
    );

    return `${shown} ${byteUnitLabels[unit]}`;
}

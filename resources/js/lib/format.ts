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

// Whether the person saved a locale at all, so a workspace locale can stand in for a missing choice (Story 3.2).
const userChoseLocale = shallowRef(false);

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
    userChoseLocale.value = Boolean(profile.locale);

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

// The locale chain: the person's saved locale, then the workspace's, then the catalogue default.
export function resolveLocale(workspaceLocale?: string | null): string {
    if (userChoseLocale.value) {
        return state.value.locale;
    }

    return usableLocale(workspaceLocale);
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
    localeOverride?: string,
): string {
    if (!readable(value)) {
        return '';
    }

    const locale = localeOverride ?? state.value.locale;
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
    localeOverride?: string,
): string {
    try {
        return formatNumber(
            value,
            { style: 'currency', currency, ...options },
            localeOverride,
        );
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
    localeOverride?: string,
): string {
    const date = toDate(value);

    if (date === null) {
        return '';
    }

    // A date without a time ("2026-10-07") names a calendar day, not an instant: it stays that day everywhere.
    const dayOnly = typeof value === 'string' && DATE_ONLY.test(value.trim());
    const locale = localeOverride ?? state.value.locale;
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
    localeOverride?: string,
): string {
    return formatDate(value, options, localeOverride);
}

// "3 hours ago" / "in 2 days": the largest whole unit between `value` and `now`. "" for an unparsable date.
export function formatRelativeTime(
    value: string | number | Date,
    now: Date = new Date(),
    localeOverride?: string,
): string {
    const date = toDate(value);

    if (date === null) {
        return '';
    }

    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
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

    return new Intl.RelativeTimeFormat(localeOverride ?? state.value.locale, {
        numeric: 'auto',
    }).format(Math.trunc(seconds / size), unit);
}

// A length of time given in whole seconds (a decimal string), as "1 hr 5 min 3 sec". "" when it is not a number of seconds.
export function formatDuration(
    seconds: string,
    localeOverride?: string,
): string {
    if (!/^\d+(\.\d*)?$/.test(seconds.trim())) {
        return '';
    }

    let rest = BigInt(seconds.trim().split('.')[0]);
    const locale = localeOverride ?? state.value.locale;
    const parts: string[] = [];

    for (const [unit, size] of [
        ['hour', 3600n],
        ['minute', 60n],
        ['second', 1n],
    ] as const) {
        const count = rest / size;
        rest %= size;

        if (count > 0n || (unit === 'second' && parts.length === 0)) {
            parts.push(
                formatNumber(
                    count,
                    { style: 'unit', unit, unitDisplay: 'short' },
                    locale,
                ),
            );
        }
    }

    return parts.join(' ');
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

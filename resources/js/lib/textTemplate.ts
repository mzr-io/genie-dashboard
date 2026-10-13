import { shiftDecimal } from '@block-types/kpi-card/v1/display';
import type { KpiCardPayload } from '@block-types/kpi-card/v1/payload';
import { formatDate, formatNumber } from '@/lib/format';

// Text Templates in the browser (Story 5.4; FR-26, NFR-11, AD-33). The server's result is the same for every viewer, so a template reaches the browser in a
// wire form: literal text with `{{` and `}}` for braces, `{-}` for a value that was null or missing, `{#<number>|<format>}` for a number,
// `{@<date>|<pattern>}` for a date and `{user.<key>}` for the viewer. Numbers and dates are formatted here with `Intl` in the viewer's locale, the viewer's
// own values come from the session profile, and everything else is literal text: nothing in a value can become markup or another token.
export type TemplateProfile = Record<string, string | null | undefined>;

export type TemplateContext = {
    profile: TemplateProfile;
    // What a token shows when it has no value: the Slot's empty display, "—" by default.
    emptyDisplay?: string | null;
    // The currency a `currency` token is written in, when the Block has one; without it the number is written plainly.
    currencyCode?: string | null;
    locale?: string;
};

const TOKEN =
    /^\{(-|#([^|}]+)(?:\|([^}]+))?|@(\d{4}-\d{2}-\d{2})(?:\|([^}]+))?|user\.([a-z_]+))\}/;

const DATE_OPTIONS: Record<string, Intl.DateTimeFormatOptions> = {
    'MMM d': { month: 'short', day: 'numeric' },
    'MMM d, yyyy': { month: 'short', day: 'numeric', year: 'numeric' },
    'MMMM yyyy': { month: 'long', year: 'numeric' },
    'MMM yyyy': { month: 'short', year: 'numeric' },
    'd MMM': { day: 'numeric', month: 'short' },
    'EEE, MMM d': { weekday: 'short', month: 'short', day: 'numeric' },
};

function number(
    value: string,
    format: string | undefined,
    ctx: TemplateContext,
): string {
    const locale = ctx.locale;

    switch (format) {
        case 'integer':
            return formatNumber(value, { maximumFractionDigits: 0 }, locale);
        case 'currency':
            return ctx.currencyCode
                ? formatNumber(
                      value,
                      { style: 'currency', currency: ctx.currencyCode },
                      locale,
                  )
                : formatNumber(value, {}, locale);
        case 'percent':
            // A percentage figure (14.2 is 14.2%): the point moves two places in the string, never through a float.
            return formatNumber(
                shiftDecimal(value, -2),
                { style: 'percent' },
                locale,
            );
        case 'compact':
            return formatNumber(value, { notation: 'compact' }, locale);
        default:
            return formatNumber(value, {}, locale);
    }
}

function date(
    value: string,
    pattern: string | undefined,
    ctx: TemplateContext,
): string {
    if (pattern === 'yyyy-MM-dd') {
        return value;
    }

    return formatDate(
        value,
        DATE_OPTIONS[pattern ?? 'MMM d, yyyy'] ?? DATE_OPTIONS['MMM d, yyyy'],
        ctx.locale,
    );
}

// A rendered template as the text the viewer reads.
export function expandTemplate(text: string, ctx: TemplateContext): string {
    const empty =
        ctx.emptyDisplay && ctx.emptyDisplay !== '' ? ctx.emptyDisplay : '—';
    let out = '';
    let i = 0;

    while (i < text.length) {
        const c = text[i];

        if (c === '{' && text[i + 1] === '{') {
            out += '{';
            i += 2;

            continue;
        }

        if (c === '}' && text[i + 1] === '}') {
            out += '}';
            i += 2;

            continue;
        }

        if (c === '{') {
            const match = TOKEN.exec(text.slice(i));

            if (match !== null) {
                const [
                    whole,
                    kind,
                    numeric,
                    numberFormat,
                    day,
                    dayPattern,
                    userKey,
                ] = match;

                if (kind === '-') {
                    out += empty;
                } else if (numeric !== undefined) {
                    out += number(numeric, numberFormat, ctx) || empty;
                } else if (day !== undefined) {
                    out += date(day, dayPattern, ctx) || empty;
                } else if (userKey !== undefined) {
                    const value = ctx.profile[userKey];

                    out +=
                        typeof value === 'string' && value !== ''
                            ? value
                            : empty;
                }

                i += whole.length;

                continue;
            }
        }

        out += c;
        i += 1;
    }

    return out;
}

const TEXT_KEYS = [
    'title',
    'subtitle',
    'kpiLabel',
    'comparisonLabel',
    'secondaryNote',
    'footerAction',
] as const;

// A KPI card's payload with the text slots expanded (only strings that carry a token change; a payload with none comes back as it was).
export function expandKpiPayload(
    payload: KpiCardPayload,
    profile: TemplateProfile,
    locale?: string,
): KpiCardPayload {
    const ctx: TemplateContext = {
        profile,
        emptyDisplay: payload.emptyDisplay,
        // Another Block Type's payload has no headline (Story 5.8c).
        currencyCode: payload.headline?.available
            ? payload.headline.format.currencyCode
            : null,
        locale,
    };
    const next: Record<string, unknown> = { ...payload };
    let changed = false;

    for (const key of TEXT_KEYS) {
        const value = payload[key];

        if (typeof value === 'string' && value.includes('{')) {
            next[key] = expandTemplate(value, ctx);
            changed = true;
        }
    }

    const badge = payload.statusBadge as unknown;

    if (typeof badge === 'string' && badge.includes('{')) {
        next.statusBadge = expandTemplate(badge, ctx);
        changed = true;
    } else if (
        badge !== null &&
        typeof badge === 'object' &&
        typeof (badge as { text?: unknown }).text === 'string' &&
        (badge as { text: string }).text.includes('{')
    ) {
        next.statusBadge = {
            ...(badge as object),
            text: expandTemplate((badge as { text: string }).text, ctx),
        };
        changed = true;
    }

    return changed ? (next as KpiCardPayload) : payload;
}

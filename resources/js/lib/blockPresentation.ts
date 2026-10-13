import { formatMeasure } from '@block-types/kpi-card/v1/display';
import type { FormatDescriptor } from '@block-types/kpi-card/v1/payload';
import { BLOCK_ICONS } from '@/lib/blockIcons';
import { blockPresentationLabels as labels } from '@/locales/labels';

// The presentation rules the Map Data step edits (Story 3.15b): `config.presentation` of the Draft, stored by the server (3.15a) as descriptors only,
// never locale-formatted text. The client keeps the same shape, edits it immutably and judges it with the server's own limits, so an invalid value
// is shown inline and never sent; the server stays the authority and its `config.presentation.<path>` errors land on the same paths.

export const FORMAT_KINDS = [
    'number',
    'percent',
    'currency',
    'compact',
    'date',
    'relative-time',
    'duration',
] as const;
export type FormatKind = (typeof FORMAT_KINDS)[number];

// The Format select's choices: "integer" is a number with 0 places.
export const FORMAT_CHOICES = [
    'number',
    'integer',
    'percent',
    'currency',
    'compact',
    'date',
    'relative-time',
    'duration',
] as const;
export type FormatChoice = (typeof FORMAT_CHOICES)[number];

export const DATE_PATTERNS = ['short', 'medium', 'long', 'full'] as const;
export const EMPHASIS_ROLES = [
    'headline',
    'primary',
    'secondary',
    'badge',
] as const;
export const EMPHASIS_SIZES = ['sm', 'md', 'lg'] as const;
export const EMPHASIS_WEIGHTS = ['regular', 'medium', 'bold'] as const;
export const DIRECTIONS = ['higher_is_better', 'lower_is_better'] as const;
// How a comparison reads its previous value (Story 5.5); the Slot's mapped field is the previous value.
export const COMPARISON_MODES = [
    'pct_change',
    'absolute_delta',
    'pp_delta',
] as const;
export type ComparisonMode = (typeof COMPARISON_MODES)[number];
export const BAND_OPS = ['>=', '<=', 'between'] as const;
export const BAND_COLORS = [
    'success',
    'warning',
    'error',
    'info',
    'neutral',
] as const;

export type BandOp = (typeof BAND_OPS)[number];
export type BandColor = (typeof BAND_COLORS)[number];

export const LIMITS = {
    places: 20,
    units: 16,
    affix: 16,
    label: 80,
    empty: 32,
    bandLabel: 40,
    bands: 10,
    decimal: 64,
} as const;

export const SLOT_HEADLINE = 'headline_value';
export const SLOT_COMPARISON = 'comparison';

export type PresentationFormat = {
    kind?: FormatKind;
    // A typed value that is not a whole number stays as text so its error can be shown.
    decimalPlaces?: number | string | null;
    thousandsSeparator?: boolean;
    currencyCode?: string | null;
    currencyDisplay?: 'symbol' | 'code';
    datePattern?: (typeof DATE_PATTERNS)[number];
    unitsSuffix?: string | null;
};

export type PresentationEmphasis = {
    role?: (typeof EMPHASIS_ROLES)[number];
    size?: (typeof EMPHASIS_SIZES)[number];
    weight?: (typeof EMPHASIS_WEIGHTS)[number];
};

export type SlotPresentation = {
    format?: PresentationFormat;
    emphasis?: PresentationEmphasis;
    direction?: (typeof DIRECTIONS)[number];
    mode?: ComparisonMode;
    label?: { text?: string; prefix?: string; suffix?: string };
};

export type PresentationBand = {
    op: BandOp;
    value: string;
    to?: string;
    color: BandColor;
    icon: string;
    label: string;
};

export type PresentationRules = {
    slots?: Record<string, SlotPresentation>;
    bands?: PresentationBand[];
    empty_display?: string;
};

// What `PUT .../mapping/presentation` answers.
export type PresentationWrite = {
    presentation: PresentationRules;
    draft_revision: number;
    query_plan: unknown;
};

export const ROOT = 'config.presentation';

// Error paths, the same strings the server uses.
export const slotPath = (slot: string, part = ''): string =>
    `${ROOT}.slots.${slot}${part === '' ? '' : `.${part}`}`;
export const bandPath = (index: number, part = ''): string =>
    `${ROOT}.bands.${index}${part === '' ? '' : `.${part}`}`;
export const EMPTY_PATH = `${ROOT}.empty_display`;

// The errors at, or under, a path (a slot's editor shows all of its own).
export function errorsUnder(
    errors: Record<string, string>,
    prefix: string,
): { path: string; message: string }[] {
    return Object.entries(errors)
        .filter(([path]) => path === prefix || path.startsWith(`${prefix}.`))
        .map(([path, message]) => ({ path, message }));
}

// ---- Editing (immutable) ----------------------------------------------------------------------------------------------------

// Drops empty strings, nulls and empty objects, so `{}` means "no rules" and a cleared field leaves nothing behind.
export function prune<T>(value: T): T | undefined {
    if (Array.isArray(value)) {
        return value as unknown as T;
    }

    if (value !== null && typeof value === 'object') {
        const out: Record<string, unknown> = {};

        for (const [key, entry] of Object.entries(value)) {
            const kept = prune(entry);

            if (kept !== undefined) {
                out[key] = kept;
            }
        }

        return Object.keys(out).length === 0 ? undefined : (out as T);
    }

    return value === null || value === '' ? undefined : value;
}

export function clean(rules: PresentationRules): PresentationRules {
    const out: PresentationRules = {};
    const slots: Record<string, SlotPresentation> = {};

    for (const [slot, entry] of Object.entries(rules.slots ?? {})) {
        const kept = prune(entry);

        if (kept !== undefined) {
            slots[slot] = kept;
        }
    }

    if (Object.keys(slots).length > 0) {
        out.slots = slots;
    }

    if ((rules.bands ?? []).length > 0) {
        out.bands = rules.bands;
    }

    if (rules.empty_display) {
        out.empty_display = rules.empty_display;
    }

    return out;
}

export function setSlot(
    rules: PresentationRules,
    slot: string,
    patch: Partial<SlotPresentation>,
): PresentationRules {
    return clean({
        ...rules,
        slots: {
            ...rules.slots,
            [slot]: { ...rules.slots?.[slot], ...patch },
        },
    });
}

export function sameRules(a: PresentationRules, b: PresentationRules): boolean {
    return JSON.stringify(clean(a)) === JSON.stringify(clean(b));
}

export function choiceOf(format: PresentationFormat | undefined): FormatChoice {
    const kind = format?.kind ?? 'number';

    return kind === 'number' && format?.decimalPlaces === 0 ? 'integer' : kind;
}

// The format a choice makes, keeping what still applies from the one before.
export function withChoice(
    format: PresentationFormat | undefined,
    choice: FormatChoice,
): PresentationFormat {
    const kind: FormatKind = choice === 'integer' ? 'number' : choice;
    const next: PresentationFormat = { ...format, kind };

    if (choice === 'integer') {
        next.decimalPlaces = 0;
    } else if (format?.decimalPlaces === 0 && kind === 'number') {
        delete next.decimalPlaces;
    }

    if (kind !== 'currency') {
        delete next.currencyCode;
        delete next.currencyDisplay;
    }

    if (kind !== 'date') {
        delete next.datePattern;
    }

    // A date or a duration has no places or thousands separator: nothing hidden is left to fail.
    if (['date', 'relative-time', 'duration'].includes(kind)) {
        delete next.decimalPlaces;
        delete next.thousandsSeparator;
    }

    return next;
}

// ---- Judging ---------------------------------------------------------------------------------------------------------------

const DECIMAL = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
const CONTROL = new RegExp(
    String.raw`[\u0000-\u001f\u007f-\u009f\u200b-\u200f\u2028-\u202e\u2060-\u2064\ufeff]`,
    'u',
);

function textFault(value: unknown, max: number): string | null {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    if (
        typeof value !== 'string' ||
        Array.from(value).length > max ||
        CONTROL.test(value)
    ) {
        return labels.textLimit(max);
    }

    return null;
}

// Every fault of the rules, keyed like the server's `config.presentation.<path>` errors. Empty when the rules can be sent.
export function judge(
    rules: PresentationRules,
    knownSlots: readonly string[] | null = null,
): Record<string, string> {
    const errors: Record<string, string> = {};
    const add = (path: string, message: string | null): void => {
        if (message !== null && errors[path] === undefined) {
            errors[path] = message;
        }
    };

    for (const [slot, entry] of Object.entries(rules.slots ?? {})) {
        if (knownSlots !== null && !knownSlots.includes(slot)) {
            add(slotPath(slot), labels.unknownSlot);

            continue;
        }

        const format = entry.format;

        if (format !== undefined) {
            const places = format.decimalPlaces;

            if (places !== undefined && places !== null && places !== '') {
                const whole =
                    typeof places === 'number'
                        ? Number.isInteger(places)
                        : /^-?\d+$/.test(places);
                const number = Number(places);

                if (!whole || number < 0 || number > LIMITS.places) {
                    add(
                        slotPath(slot, 'format.decimalPlaces'),
                        labels.placesRange(LIMITS.places),
                    );
                }
            }

            const code = format.currencyCode;

            if (code !== undefined && code !== null && code !== '') {
                if (!/^[A-Za-z]{3}$/.test(code)) {
                    add(
                        slotPath(slot, 'format.currencyCode'),
                        labels.currencyShape,
                    );
                }
            } else if (format.kind === 'currency') {
                add(
                    slotPath(slot, 'format.currencyCode'),
                    labels.currencyNeeded,
                );
            }

            add(
                slotPath(slot, 'format.unitsSuffix'),
                textFault(format.unitsSuffix, LIMITS.units),
            );
        }

        if (entry.direction !== undefined && slot !== SLOT_COMPARISON) {
            add(slotPath(slot, 'direction'), labels.directionComparisonOnly);
        }

        if (entry.mode !== undefined && slot !== SLOT_COMPARISON) {
            add(slotPath(slot, 'mode'), labels.modeComparisonOnly);
        }

        add(
            slotPath(slot, 'label.text'),
            textFault(entry.label?.text, LIMITS.label),
        );
        add(
            slotPath(slot, 'label.prefix'),
            textFault(entry.label?.prefix, LIMITS.affix),
        );
        add(
            slotPath(slot, 'label.suffix'),
            textFault(entry.label?.suffix, LIMITS.affix),
        );
    }

    const bands = rules.bands ?? [];

    if (bands.length > LIMITS.bands) {
        add(`${ROOT}.bands`, labels.bandsMax(LIMITS.bands));
    }

    bands.forEach((band, index) => {
        const decimal = (value: unknown): boolean =>
            typeof value === 'string' &&
            value.length <= LIMITS.decimal &&
            DECIMAL.test(value.trim());

        if (!decimal(band.value)) {
            add(bandPath(index, 'value'), labels.decimalNeeded);
        }

        if (band.op === 'between' && !decimal(band.to)) {
            add(bandPath(index, 'to'), labels.decimalNeeded);
        }

        if (!BAND_COLORS.includes(band.color)) {
            add(bandPath(index, 'color'), labels.colorNeeded);
        }

        if (typeof band.icon !== 'string' || band.icon === '') {
            add(bandPath(index, 'icon'), labels.bandIconNeeded);
        } else if (BLOCK_ICONS[band.icon] === undefined) {
            add(bandPath(index, 'icon'), labels.bandIconUnknown);
        }

        if (typeof band.label !== 'string' || band.label.trim() === '') {
            add(bandPath(index, 'label'), labels.bandLabelNeeded);
        } else {
            add(
                bandPath(index, 'label'),
                textFault(band.label, LIMITS.bandLabel),
            );
        }
    });

    add(EMPTY_PATH, textFault(rules.empty_display, LIMITS.empty));

    return errors;
}

// Server errors come as `{path: [message, ...]}`: the first message of each path under `config.presentation`.
export function serverErrors(
    fields: Record<string, string[]>,
): Record<string, string> {
    const out: Record<string, string> = {};

    for (const [path, messages] of Object.entries(fields)) {
        if (path === ROOT || path.startsWith(`${ROOT}.`)) {
            out[path] = messages[0] ?? labels.invalid;
        }
    }

    return out;
}

// ---- The live example -------------------------------------------------------------------------------------------------------

// A complete descriptor, filled as the Shaper fills one (same defaults), so the example is what the Renderer will draw.
export function descriptorOf(
    format: PresentationFormat | undefined,
): FormatDescriptor {
    const places = format?.decimalPlaces;
    const code = format?.currencyCode;
    const suffix = format?.unitsSuffix;

    return {
        kind: FORMAT_KINDS.includes(format?.kind as FormatKind)
            ? (format!.kind as FormatKind)
            : 'number',
        decimalPlaces:
            typeof places === 'number' &&
            Number.isInteger(places) &&
            places >= 0 &&
            places <= LIMITS.places
                ? places
                : typeof places === 'string' &&
                    /^\d{1,2}$/.test(places) &&
                    Number(places) <= LIMITS.places
                  ? Number(places)
                  : null,
        thousandsSeparator: format?.thousandsSeparator !== false,
        currencyCode:
            typeof code === 'string' && /^[A-Za-z]{3}$/.test(code)
                ? code.toUpperCase()
                : null,
        currencyDisplay: format?.currencyDisplay === 'code' ? 'code' : 'symbol',
        datePattern: DATE_PATTERNS.includes(
            format?.datePattern as (typeof DATE_PATTERNS)[number],
        )
            ? (format!.datePattern as FormatDescriptor['datePattern'])
            : 'medium',
        unitsSuffix:
            typeof suffix === 'string' && suffix !== '' ? suffix : null,
    };
}

// The value the example formats: the slot's sample when it fits the kind, else a stand-in of the right shape.
export function sampleFor(kind: FormatKind, example: string | null): string {
    const given = example?.trim() ?? '';

    if (kind === 'date' || kind === 'relative-time') {
        return given !== '' && !Number.isNaN(Date.parse(given))
            ? given
            : new Date(Date.now() - 3 * 86_400_000).toISOString();
    }

    if (kind === 'duration') {
        return /^\d+(\.\d*)?$/.test(given) ? given : '3725';
    }

    return DECIMAL.test(given) ? given : '284680';
}

// "$284,680": the format applied through the Renderer's own descriptor path (`Intl`, the person's or the browser's locale). Null when the
// format cannot show a value yet (a currency without a code, an invalid code).
export function exampleFor(
    format: PresentationFormat | undefined,
    example: string | null,
    locale: string,
): string | null {
    const descriptor = descriptorOf(format);

    if (descriptor.kind === 'currency' && descriptor.currencyCode === null) {
        return null;
    }

    try {
        const shown = formatMeasure(
            sampleFor(descriptor.kind, example),
            descriptor,
            locale,
        );

        return shown === '' ? null : shown;
    } catch {
        return null;
    }
}

const PLAIN_DECIMAL = /^([+-]?)(\d*)\.?(\d*)$/;

// A plain decimal as an integer over a power of ten, so the example is exact: never a float.
function scaled(value: string): { n: bigint; places: number } | null {
    const match = PLAIN_DECIMAL.exec(value.trim());

    if (match === null || match[2] + match[3] === '') {
        return null;
    }

    const n = BigInt(match[2] + match[3]);

    return { n: match[1] === '-' ? -n : n, places: match[3].length };
}

function plainOf(n: bigint, places: number): string {
    const negative = n < 0n;
    const digits = (negative ? -n : n).toString().padStart(places + 1, '0');
    const whole = digits.slice(0, digits.length - places);
    const fraction = digits.slice(digits.length - places).replace(/0+$/, '');

    return `${negative && /[1-9]/.test(whole + fraction) ? '-' : ''}${whole}${fraction === '' ? '' : `.${fraction}`}`;
}

// The change a comparison mode makes (the server's own arithmetic, to six places), or null when there is none: a missing value, or a previous value of
// 0 for a percentage.
export function comparisonChange(
    mode: ComparisonMode,
    current: string | null | undefined,
    previous: string | null | undefined,
): string | null {
    const a = scaled(current ?? '');
    const b = scaled(previous ?? '');

    if (a === null || b === null) {
        return null;
    }

    const places = Math.max(a.places, b.places);
    const x = a.n * 10n ** BigInt(places - a.places);
    const y = b.n * 10n ** BigInt(places - b.places);

    if (mode !== 'pct_change') {
        return plainOf(x - y, places);
    }

    if (y === 0n) {
        return null;
    }

    // (x - y) * 100 / y at six places, rounded half away from zero.
    const numerator = (x - y) * 100n * 10n ** 6n;
    const top = numerator < 0n ? -numerator : numerator;
    const bottom = y < 0n ? -y : y;
    const quotient = (2n * top + bottom) / (2n * bottom);

    return plainOf(numerator < 0n !== y < 0n ? -quotient : quotient, 6);
}

// The kpi-card v1 render payload (Story 3.2): what `Shaper.php` emits and `Renderer.vue` draws. Measures are decimal strings, never
// JS numbers, and nothing here is formatted for a locale: a format descriptor says how, and the browser applies it with `Intl`.

export type FormatKind =
    | 'number'
    | 'percent'
    | 'currency'
    | 'compact'
    | 'date'
    | 'relative-time'
    | 'duration';

export type FormatDescriptor = {
    kind: FormatKind;
    // Fraction digits; absent keeps every digit of the decimal string.
    decimalPlaces: number | null;
    thousandsSeparator: boolean;
    // ISO 4217 code, for `currency`.
    currencyCode: string | null;
    // Show the symbol ("$") or the code ("USD").
    currencyDisplay: 'symbol' | 'code';
    // For `date`.
    datePattern: 'short' | 'medium' | 'long' | 'full';
    // Literal text appended after the value ("kg"), or null.
    unitsSuffix: string | null;
};

export type KpiCardState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type DeltaDirection = 'up' | 'down' | 'flat';

export type KpiCardPayload = {
    state: KpiCardState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    kpiLabel: string | null;
    // The headline measure: a decimal string, or the name of the slot that is unavailable.
    headline:
        | { available: true; value: string; format: FormatDescriptor }
        | { available: false; field: string };
    comparison: {
        value: string;
        format: FormatDescriptor;
        direction: DeltaDirection;
        lowerIsBetter: boolean;
    } | null;
    comparisonLabel: string | null;
    secondaryNote: string | null;
    statusBadge: string | null;
    // An ISO 8601 instant, for the `stale` state.
    staleSince: string | null;
    // Literal text for the `error` state; null uses the card's own message.
    errorMessage: string | null;
};

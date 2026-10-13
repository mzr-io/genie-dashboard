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

export type EmphasisRole = 'headline' | 'primary' | 'secondary' | 'badge';

export type Emphasis = {
    role: EmphasisRole;
    size?: 'sm' | 'md' | 'lg';
    weight?: 'regular' | 'medium' | 'bold';
};

export type BandColor = 'success' | 'warning' | 'error' | 'info' | 'neutral';

// The threshold band the Query Plan engine matched (Story 3.15a): its label and icon say it too, so colour is never the only signal.
export type StatusBadge = {
    text: string;
    color: BandColor;
    // An icon key of the block icon set.
    icon: string;
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
        | {
              available: true;
              value: string;
              format: FormatDescriptor;
              // Present only when the Draft's presentation sets it.
              emphasis?: Emphasis;
          }
        | { available: false; field: string };
    comparison: {
        value: string;
        format: FormatDescriptor;
        direction: DeltaDirection;
        lowerIsBetter: boolean;
        // The change writes its own minus sign (an absolute or point change), beside the arrow.
        signed?: boolean;
    } | null;
    comparisonLabel: string | null;
    secondaryNote: string | null;
    // A matched threshold band, or the text of the status_badge slot when no band matched.
    statusBadge: string | StatusBadge | null;
    // Literal text before and after the headline value (presentation rules), or null.
    prefix?: string | null;
    suffix?: string | null;
    // Shown for a mapped slot with no value (never for the headline); `emptySlots` names those slots.
    emptyDisplay?: string | null;
    emptySlots?: string[];
    // An ISO 8601 instant, for the `stale` state.
    staleSince: string | null;
    // Literal text for the `error` state; null uses the card's own message.
    errorMessage: string | null;
};

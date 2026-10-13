// The line-area v1 render payload (Story 5.8c): what `Shaper.php` returns and `Renderer.vue` reads. Measures are decimal strings, never numbers, and
// nothing is formatted for a locale: the Renderer formats with `Intl` from the descriptor and the viewer's locale.

export type LineAreaState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type LineAreaFormat = {
    kind: 'number' | 'percent' | 'currency' | 'compact';
    decimalPlaces: number | null;
    thousandsSeparator: boolean;
    currencyCode: string | null;
    currencyDisplay: 'symbol' | 'code';
};

export type LineAreaSeries = {
    key: string;
    name: string;
    // One decimal string per x position, null where a row has no value.
    values: (string | null)[];
};

export type LineAreaPayload = {
    state: LineAreaState;
    kind: 'line' | 'area';
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    // The axis values as received (an ISO date, a date-time or a label), one per row.
    x: string[];
    // The axis column's name in a table (the X slot's label).
    xLabel?: string | null;
    series: LineAreaSeries[];
    // At least one row of at least one series has no value.
    partial: boolean;
    // The x position of the highlighted point, or null.
    highlight: number | null;
    target: { value: string; label: string | null } | null;
    format: LineAreaFormat;
    directLabels: boolean;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

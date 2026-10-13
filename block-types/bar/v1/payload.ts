// The bar v1 render payload (Story 5.11): what `Shaper.php` returns and `Renderer.vue` reads. Measures are decimal strings; nothing is formatted.

export type BarState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type BarFormat = {
    kind: 'number' | 'percent' | 'currency' | 'compact';
    decimalPlaces: number | null;
    thousandsSeparator: boolean;
    currencyCode: string | null;
    currencyDisplay: 'symbol' | 'code';
};

export type BarPayload = {
    state: BarState;
    kind: 'grouped' | 'stacked';
    orientation: 'vertical' | 'horizontal';
    valueLabels: boolean;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    categories: string[];
    categoryLabel?: string | null;
    series: { key: string; name: string; values: (string | null)[] }[];
    // The total of each stack (stacked only), aligned with `categories`.
    totals: (string | null)[];
    partial: boolean;
    format: BarFormat;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

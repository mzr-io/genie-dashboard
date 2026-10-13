// The progress-list v1 render payload (Story 5.13): what `Shaper.php` returns and `Renderer.vue` reads. Percents are decimal strings, never floats.

export type ProgressListState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type ProgressRow = {
    // Stable across refreshes: the mapped key, else a hash of the cells, made unique by its occurrence. Never a position.
    key: string;
    title: string | null;
    caption: string | null;
    // The true percent as text ("75", "130"); null when the total is 0 or a value is missing.
    percent: string | null;
    // The bar's width, 0 to 100: a percent over 100 fills the track.
    fill: string | null;
    band: {
        text: string;
        color: 'success' | 'warning' | 'error' | 'info' | 'neutral';
        icon: string;
    } | null;
};

export type ProgressListPayload = {
    state: ProgressListState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    rows: ProgressRow[];
    partial: boolean;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

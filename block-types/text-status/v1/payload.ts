// The text-status v1 render payload (Story 5.14): what `Shaper.php` returns and `Renderer.vue` reads.

export type TextStatusState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type TextStatusPayload = {
    state: TextStatusState;
    // Text: the text, up to three lines. Status: a pill.
    mode: 'text' | 'status';
    title: string | null;
    icon: string | null;
    footerAction: string | null;
    text: string | null;
    // The band the value matched, in Status mode; null when it matched none.
    band: {
        text: string;
        color: 'success' | 'warning' | 'error' | 'info' | 'neutral';
        icon: string;
    } | null;
    partial: boolean;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

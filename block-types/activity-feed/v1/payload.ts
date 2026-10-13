// The activity-feed v1 render payload (Story 5.14): what `Shaper.php` returns and `Renderer.vue` reads. "How long ago" is the viewer's, never written here.

export type ActivityState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type ActivityRow = {
    // Stable across refreshes: the mapped key, else a hash of the cells, made unique by its occurrence. Never a position.
    key: string;
    actor: string | null;
    action: string | null;
    object: string | null;
    // The instant as it was written; null when it is not a real date.
    timestamp: string | null;
    avatar: { initials: string; tint: number } | null;
};

export type ActivityPayload = {
    state: ActivityState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    rows: ActivityRow[];
    partial: boolean;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

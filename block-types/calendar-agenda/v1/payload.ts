// The calendar-agenda v1 render payload (Story 5.14): what `Shaper.php` returns and `Renderer.vue` reads. A date is a calendar day and a time is the
// wall clock as written; neither is moved by a time zone.

export type AgendaState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type AgendaRow = {
    // Stable across refreshes: the mapped key, else a hash of the cells, made unique by its occurrence. Never a position.
    key: string;
    date: string | null;
    time: string | null;
    title: string | null;
    subtitle: string | null;
    // Whole minutes as text.
    duration: string | null;
    category: string | null;
    // One of six chart colours, chosen from the category word alone.
    tint: number | null;
};

export type AgendaPayload = {
    state: AgendaState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    rows: AgendaRow[];
    weekStrip: boolean;
    partial: boolean;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

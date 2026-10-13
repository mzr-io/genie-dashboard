import type { TableFormat } from '../../table/v1/payload';

// The list v1 render payload (Story 5.13): what `Shaper.php` returns and `Renderer.vue` reads. Texts are as received; nothing is formatted.

export type ListState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type ListRow = {
    // Stable across refreshes: the mapped key, else a hash of the cells, made unique by its occurrence. Never a position.
    key: string;
    title: string | null;
    subtitle: string | null;
    // The initials of the mapped name and one of five tints, chosen by the name alone.
    avatar: { initials: string; tint: number } | null;
    icon: string | null;
    value: string | null;
    meta: string | null;
    status: string | null;
};

export type ListPayload = {
    state: ListState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    rows: ListRow[];
    partial: boolean;
    valueFormat: TableFormat | null;
    metaFormat: TableFormat | null;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

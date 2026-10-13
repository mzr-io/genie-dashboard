// The table v1 render payload (Story 5.12): what `Shaper.php` returns and `Renderer.vue` reads. Cells are text as received; nothing is formatted.

export type TableState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type TableFormat = {
    kind: 'number' | 'percent' | 'currency' | 'compact' | 'date';
    decimalPlaces: number | null;
    thousandsSeparator: boolean;
    currencyCode: string | null;
    currencyDisplay: 'symbol' | 'code';
    datePattern: string;
    unitsSuffix: string | null;
};

export type TableColumn = {
    key: string;
    header: string;
    align: 'left' | 'right' | 'center';
    width: number | null;
    sortable: boolean;
    format: TableFormat | null;
};

export type TableRow = {
    // Stable across refreshes: the mapped key, else a hash of the cells, made unique by its occurrence. Never a position.
    key: string;
    cells: (string | null)[];
    highlight: {
        text: string;
        color: 'success' | 'warning' | 'error' | 'info' | 'neutral';
        icon: string;
    } | null;
};

export type TablePayload = {
    state: TableState;
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    columns: TableColumn[];
    rows: TableRow[];
    partial: boolean;
    pageSize: number;
    // The period the rows are for: the table starts again at its first page when it changes.
    periodKey: string | null;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

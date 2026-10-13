// The pie-donut v1 render payload (Story 5.11): the slices as the Shaper made them (folded, ordered); nothing is formatted.
import type { BarFormat } from '../../bar/v1/payload';

export type PieState =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'stale'
    | 'unavailable'
    | 'error';

export type PiePayload = {
    state: PieState;
    kind: 'pie' | 'donut';
    title: string | null;
    subtitle: string | null;
    icon: string | null;
    footerAction: string | null;
    categories: string[];
    categoryLabel?: string | null;
    // One decimal string per slice (null where the row had none).
    values: (string | null)[];
    valueLabel?: string | null;
    // The rows beyond the sixth slice were added into "Other".
    folded: boolean;
    // The text in the middle of a donut: a label and a value.
    centre: { label: string | null; value: string | null } | null;
    partial: boolean;
    format: BarFormat;
    emptyDisplay: string | null;
    staleSince: string | null;
    errorMessage: string | null;
};

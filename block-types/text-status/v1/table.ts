// The text-status Block has no rows, so it has no table (Story 5.9): this is what its cells would read as, if one is asked for.
export function cell(_key: string, value: string | boolean | null): string {
    return value === null ? '—' : String(value);
}

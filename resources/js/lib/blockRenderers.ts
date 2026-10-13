import { defineAsyncComponent } from 'vue';
import type { Component } from 'vue';

// The client side of the Block Type registry (Story 4.5, AD-21; Story 5.17): a Block Type key to its Renderer, loaded when a Block of that type is first
// drawn, and to its table for "View as table". Both are found by the place of the package (`block-types/{key}/v{N}/Renderer.vue`, `table.ts`), so a new
// package is picked up with no change here. Every Renderer implements every state and interpolates slots as literal text; a type with no Renderer is
// drawn as unavailable, never as nothing.
const rendererFiles = import.meta.glob(
    '../../../block-types/*/v*/Renderer.vue',
);
const tableFiles = import.meta.glob('../../../block-types/*/v*/table.ts', {
    eager: true,
}) as Record<string, Record<string, unknown>>;

const PACKAGE = /block-types\/([a-z0-9-]+)\/v(\d+)\//;

// The newest contract of each key wins (a Block is drawn by the contract it pins once that is carried on the payload; until then the newest).
function newest<T>(files: Record<string, T>): Record<string, T> {
    const best: Record<string, { version: number; value: T }> = {};

    for (const [path, value] of Object.entries(files)) {
        const found = PACKAGE.exec(path);

        if (found === null) {
            continue;
        }

        const version = Number(found[2]);

        if (best[found[1]] === undefined || best[found[1]].version < version) {
            best[found[1]] = { version, value };
        }
    }

    return Object.fromEntries(
        Object.entries(best).map(([key, { value }]) => [key, value]),
    );
}

export const blockRenderers: Record<string, Component> = Object.fromEntries(
    Object.entries(newest(rendererFiles)).map(([key, load]) => [
        key,
        defineAsyncComponent(load as () => Promise<{ default: Component }>),
    ]),
);

export const rendererFor = (type: string): Component | null =>
    blockRenderers[type] ?? null;

// A Block Type that gives its payload as a table (Story 5.9): "View as table" is offered for exactly these types.
export type TableModel = {
    columns: { key: string; label: string }[];
    rows: string[][];
};

export type TableBuilder = {
    // The table of the displayed payload, every cell formatted as the Block formats it.
    table: (payload: Record<string, unknown>, locale: string) => TableModel;
    // One cell of a rows page.
    cell: (
        key: string,
        value: string | boolean | null,
        payload: Record<string, unknown>,
        locale: string,
    ) => string;
};

type TableModule = {
    tableOf?: (payload: never, locale: string) => TableModel;
    cell?: (
        key: string,
        value: string | boolean | null,
        payload: never,
        locale: string,
    ) => string;
};

// A package's `table.ts` is a builder when it exports both `tableOf` and `cell`.
const builders: Record<string, TableBuilder> = {};

for (const [key, module] of Object.entries(newest(tableFiles))) {
    const { tableOf, cell } = module as TableModule;

    if (typeof tableOf === 'function' && typeof cell === 'function') {
        builders[key] = {
            table: (payload, locale) => tableOf(payload as never, locale),
            cell: (k, value, payload, locale) =>
                cell(k, value, payload as never, locale),
        };
    }
}

// The chart of a KPI & Chart payload is a line-area payload under `chart`: the one Block Type that reads another's table.
const lineArea = builders['line-area'];

if (lineArea !== undefined) {
    const chartOf = (payload: Record<string, unknown>) =>
        (payload.chart ?? {}) as Record<string, unknown>;

    builders['kpi-chart'] = {
        table: (payload, locale) => lineArea.table(chartOf(payload), locale),
        cell: (key, value, payload, locale) =>
            lineArea.cell(key, value, chartOf(payload), locale),
    };
}

export const tableBuilders: Record<string, TableBuilder> = builders;

export const tableFor = (type: string): TableBuilder | null =>
    tableBuilders[type] ?? null;

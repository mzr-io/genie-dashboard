import { defineAsyncComponent } from 'vue';
import type { Component } from 'vue';
import * as lineAreaTable from '@block-types/line-area/v1/table';

// The client side of the Block Type registry (Story 4.5, AD-21): a Block Type key to its Renderer, loaded when a Block of that type is first drawn.
// Every Renderer implements every state and interpolates slots as literal text; a type with no Renderer is drawn as unavailable, never as nothing.
export const blockRenderers: Record<string, Component> = {
    'kpi-card': defineAsyncComponent(
        () => import('@block-types/kpi-card/v1/Renderer.vue'),
    ),
    'line-area': defineAsyncComponent(
        () => import('@block-types/line-area/v1/Renderer.vue'),
    ),
    'kpi-chart': defineAsyncComponent(
        () => import('@block-types/kpi-chart/v1/Renderer.vue'),
    ),
};

export const rendererFor = (type: string): Component | null =>
    blockRenderers[type] ?? null;

// A Block Type that is a chart gives its payload as a table (Story 5.9): "View as table" is offered for exactly these types.
export type TableModel = {
    columns: { key: string; label: string }[];
    rows: string[][];
};

export type TableBuilder = {
    // The table of the displayed payload, every cell formatted as the chart formats it.
    table: (payload: Record<string, unknown>, locale: string) => TableModel;
    // One cell of a rows page.
    cell: (
        key: string,
        value: string | boolean | null,
        payload: Record<string, unknown>,
        locale: string,
    ) => string;
};

// The chart of a KPI & Chart payload is a line-area payload under `chart`.
const chartOf = (payload: Record<string, unknown>): never =>
    (payload.chart ?? {}) as never;

export const tableBuilders: Record<string, TableBuilder> = {
    'kpi-chart': {
        table: (payload, locale) =>
            lineAreaTable.tableOf(chartOf(payload), locale),
        cell: (key, value, payload, locale) =>
            lineAreaTable.cell(key, value, chartOf(payload), locale),
    },
    'line-area': {
        table: (payload, locale) =>
            lineAreaTable.tableOf(payload as never, locale),
        cell: (key, value, payload, locale) =>
            lineAreaTable.cell(key, value, payload as never, locale),
    },
};

export const tableFor = (type: string): TableBuilder | null =>
    tableBuilders[type] ?? null;

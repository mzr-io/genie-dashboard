import { WizardError, wizardCall } from '@/lib/blockWizard';
import { isUserBinding } from '@/lib/endpoints';
import type { Binding, Method } from '@/lib/endpoints';
import { blockSourceLabels as labels } from '@/locales/labels';

// Wizard Step 2 (Story 3.7): which Data Source and Endpoint a block reads, how the Endpoint's Fixed parameters are valued, how often it
// refreshes and its default date range. The reads go through `/api/v1/admin/blocks/*` (`blocks.edit`), so an Admin who may build blocks but not
// manage Data Sources can still choose one. The server owns every rule and pins the revisions itself; the client never sends a pin.
export const SOURCES_URL = '/api/v1/admin/blocks/data-sources';
export const INTERVALS_URL = '/api/v1/admin/blocks/refresh-intervals';

export const DATE_PRESETS = [
    'today',
    'this_week',
    'this_month',
    'current_year',
    'custom',
] as const;

export type DatePreset = (typeof DATE_PRESETS)[number];

// The refresh interval stored in the Draft: whole seconds from System settings, or Live.
export type RefreshChoice = number | 'live';

export type SourceOption = {
    data_source_id: string;
    name: string;
    health: string;
    live_capable: boolean;
    revision: number;
};

export type EndpointParam = {
    name: string;
    binding: Binding;
    value: string | null;
    kind: 'path' | 'query' | 'body';
};

export type EndpointOption = {
    endpoint_id: string;
    data_source_id: string;
    method: Method;
    path: string;
    params: EndpointParam[];
    read_only_query: boolean;
    requires_user_context: boolean;
    revision: number;
    revision_id: string;
};

// The `source` key of the Draft's config, as the server stores it. The two pins are the server's: the client reads them and never sends them.
export type SourceConfig = {
    data_source_id?: string | null;
    endpoint_id?: string | null;
    bindings?: Record<string, string>;
    refresh_interval?: RefreshChoice | null;
    default_date_range?: { preset: DatePreset; from?: string; to?: string };
    sample_out_of_date?: boolean;
    endpoint_revision_id?: string;
    data_source_revision?: number;
};

// Step 2's form state: the page owns it, the step edits it in place.
export type SourceForm = {
    dataSourceId: string | null;
    endpointId: string | null;
    // A value for a Fixed row; a row without one uses the Endpoint's own value.
    bindings: Record<string, string>;
    refresh: RefreshChoice | null;
    preset: DatePreset | '';
    from: string;
    to: string;
    outOfDate: boolean;
    // What the server pinned when the selection was last saved.
    endpointRevisionId: string | null;
    dataSourceRevision: number | null;
    // The `post-readonly` checkbox: not saved, asked again each time a POST Endpoint is chosen.
    readOnlyAck: boolean;
};

export function emptySource(): SourceForm {
    return {
        dataSourceId: null,
        endpointId: null,
        bindings: {},
        refresh: null,
        preset: '',
        from: '',
        to: '',
        outOfDate: false,
        endpointRevisionId: null,
        dataSourceRevision: null,
        readOnlyAck: false,
    };
}

// A stored `source` is an object, but an empty PHP array arrives as `[]`: both read as "nothing chosen".
export function sourceFormOf(saved: SourceConfig | undefined): SourceForm {
    const form = emptySource();

    if (!saved || Array.isArray(saved) || typeof saved !== 'object') {
        return form;
    }

    form.dataSourceId = saved.data_source_id ?? null;
    form.endpointId = saved.endpoint_id ?? null;
    form.bindings =
        saved.bindings && !Array.isArray(saved.bindings)
            ? { ...saved.bindings }
            : {};
    form.refresh = saved.refresh_interval ?? null;
    form.preset = saved.default_date_range?.preset ?? '';
    form.from = saved.default_date_range?.from ?? '';
    form.to = saved.default_date_range?.to ?? '';
    form.outOfDate = saved.sample_out_of_date === true;
    form.endpointRevisionId = saved.endpoint_revision_id ?? null;
    form.dataSourceRevision = saved.data_source_revision ?? null;

    return form;
}

// What a save sends: no pin, no acknowledgement. Null ids say "nothing chosen".
export function sourceConfigOf(form: SourceForm): SourceConfig {
    return {
        data_source_id: form.dataSourceId,
        endpoint_id: form.endpointId,
        bindings: { ...form.bindings },
        refresh_interval: form.refresh,
        ...(form.preset === ''
            ? {}
            : {
                  default_date_range: {
                      preset: form.preset,
                      ...(form.preset === 'custom'
                          ? {
                                ...(form.from === ''
                                    ? {}
                                    : { from: form.from }),
                                ...(form.to === '' ? {} : { to: form.to }),
                            }
                          : {}),
                  },
              }),
        sample_out_of_date: form.outOfDate,
    };
}

// Whether anything is chosen: a Draft that never touched Step 2 sends no `source`, so Step 1's save stays exactly what it was.
export function sourceIsSet(form: SourceForm): boolean {
    return (
        form.dataSourceId !== null ||
        form.endpointId !== null ||
        Object.keys(form.bindings).length > 0 ||
        form.refresh !== null ||
        form.preset !== '' ||
        form.outOfDate
    );
}

// ---- Reads ----------------------------------------------------------------------------------------------------

function list<T>(body: { data?: unknown }): T[] {
    if (!Array.isArray(body.data)) {
        throw new WizardError(500);
    }

    return body.data as T[];
}

export async function fetchSources(
    signal?: AbortSignal,
): Promise<SourceOption[]> {
    return list<SourceOption>(
        await wizardCall('GET', SOURCES_URL, undefined, signal),
    );
}

export async function fetchEndpointOptions(
    dataSourceId: string,
    signal?: AbortSignal,
): Promise<EndpointOption[]> {
    return list<EndpointOption>(
        await wizardCall(
            'GET',
            `${SOURCES_URL}/${encodeURIComponent(dataSourceId)}/endpoints`,
            undefined,
            signal,
        ),
    );
}

// The System-settings intervals in whole seconds; null when none is set up (none is offered, none invented).
export async function fetchIntervals(
    signal?: AbortSignal,
): Promise<number[] | null> {
    const body = await wizardCall('GET', INTERVALS_URL, undefined, signal);
    const data = body.data as { intervals?: unknown } | undefined;

    if (!data || (data.intervals !== null && !Array.isArray(data.intervals))) {
        throw new WizardError(500);
    }

    return data.intervals === null
        ? null
        : (data.intervals as unknown[]).filter(
              (value): value is number =>
                  typeof value === 'number' && value > 0,
          );
}

// ---- Rules ----------------------------------------------------------------------------------------------------

// `GET /api/v2/finance/revenue`
export function endpointText(
    endpoint: Pick<EndpointOption, 'method' | 'path'>,
) {
    return `${endpoint.method} ${endpoint.path}`;
}

// "1 minute", "5 minutes", "60 minutes", "Daily": a whole-second interval as the words an Admin reads.
export function intervalText(seconds: number): string {
    if (seconds % 86400 === 0) {
        return seconds === 86400 ? labels.daily : labels.days(seconds / 86400);
    }

    if (seconds >= 60 && seconds % 60 === 0) {
        return labels.minutes(seconds / 60);
    }

    return labels.seconds(seconds);
}

export const refreshText = (choice: RefreshChoice): string =>
    choice === 'live' ? labels.live : intervalText(choice);

const pad = (n: number): string => String(n).padStart(2, '0');

export function isoDate(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

// The dates a default range stands for: the week starts on Monday; null while the range is not chosen or not complete.
export function resolveRange(
    form: Pick<SourceForm, 'preset' | 'from' | 'to'>,
    today: Date = new Date(),
): { from: string; to: string } | null {
    const day = new Date(
        today.getFullYear(),
        today.getMonth(),
        today.getDate(),
    );

    switch (form.preset) {
        case 'today':
            return { from: isoDate(day), to: isoDate(day) };
        case 'this_week': {
            const start = new Date(day);

            start.setDate(day.getDate() - ((day.getDay() + 6) % 7));

            const end = new Date(start);

            end.setDate(start.getDate() + 6);

            return { from: isoDate(start), to: isoDate(end) };
        }
        case 'this_month':
            return {
                from: isoDate(new Date(day.getFullYear(), day.getMonth(), 1)),
                to: isoDate(new Date(day.getFullYear(), day.getMonth() + 1, 0)),
            };
        case 'current_year':
            return {
                from: `${day.getFullYear()}-01-01`,
                to: `${day.getFullYear()}-12-31`,
            };
        case 'custom':
            return form.from !== '' && form.to !== ''
                ? { from: form.from, to: form.to }
                : null;
        default:
            return null;
    }
}

// The custom range's own rule: the start is not after the end (ISO dates compare as text).
export function customRangeError(
    form: Pick<SourceForm, 'preset' | 'from' | 'to'>,
): string | null {
    return form.preset === 'custom' &&
        form.from !== '' &&
        form.to !== '' &&
        form.from > form.to
        ? labels.rangeOrder
        : null;
}

export type ParamRow = {
    name: string;
    kind: EndpointParam['kind'];
    binding: Binding;
    // Fixed: the value in the box (the block's own, else the Endpoint's). Bound: null.
    value: string | null;
    // A bound row's resolution, read-only: "from = 2026-01-01".
    resolved: string | null;
    userContext: boolean;
    // A Block Period Selector row while the block does not use its own selector.
    unavailable: boolean;
};

const PARTS: Partial<Record<Binding, 'from' | 'to' | 'start' | 'end'>> = {
    date_range_from: 'from',
    date_range_to: 'to',
    period_start: 'start',
    period_end: 'end',
};

const SYMBOLS: Partial<Record<Binding, string>> = {
    date_range_from: 'date_range.from',
    date_range_to: 'date_range.to',
    period_start: 'period.start',
    period_end: 'period.end',
};

export function paramRows(
    endpoint: EndpointOption | null,
    form: SourceForm,
    period: string,
    today: Date = new Date(),
): ParamRow[] {
    const range = resolveRange(form, today);

    return (endpoint?.params ?? []).map((param) => {
        const part = PARTS[param.binding];
        const own = period === 'own_selector';
        const unavailable =
            (param.binding === 'period_start' ||
                param.binding === 'period_end') &&
            !own;
        const date = range
            ? part === 'from' || part === 'start'
                ? range.from
                : range.to
            : null;

        return {
            name: param.name,
            kind: param.kind,
            binding: param.binding,
            value:
                param.binding === 'fixed'
                    ? (form.bindings[param.name] ?? param.value ?? '')
                    : null,
            resolved:
                part === undefined || unavailable
                    ? null
                    : `${part} = ${date ?? SYMBOLS[param.binding]}`,
            userContext: isUserBinding(param.binding),
            unavailable,
        };
    });
}

// The names of path parameters with no value to send: an empty Fixed one blocks Fetch and is named (a bound or user one is filled in at fetch).
export function emptyPathParams(rows: ParamRow[]): string[] {
    return rows
        .filter(
            (row) =>
                row.kind === 'path' &&
                row.binding === 'fixed' &&
                (row.value ?? '').trim() === '',
        )
        .map((row) => row.name);
}

// "Shared data": the Endpoint has no user-context binding, so everyone sees the same data.
export const isSharedData = (endpoint: EndpointOption | null): boolean =>
    endpoint !== null &&
    !endpoint.requires_user_context &&
    !endpoint.params.some((param) => isUserBinding(param.binding));

// Whether a change touches what the request is (the Data Source, the Endpoint, a parameter value or the default range that dates the
// bound parameters): then a sample taken before it is out of date. The refresh interval does not change the request.
export function requestKey(form: SourceForm): string {
    return JSON.stringify([
        form.dataSourceId,
        form.endpointId,
        Object.entries(form.bindings).sort(([a], [b]) => a.localeCompare(b)),
        form.preset,
        form.preset === 'custom' ? [form.from, form.to] : null,
    ]);
}

export type Gate = { fetch: string | undefined; continue: string | undefined };

// Why Fetch and Continue cannot run: the first reason wins, and each is said inline beside the button. `none` is a Workspace with no Data Source.
export function stepGate(
    sources: SourceOption[] | null,
    endpoint: EndpointOption | null,
    rows: ParamRow[],
    form: SourceForm,
    noneReason: string,
): Gate {
    if (sources !== null && sources.length === 0) {
        return { fetch: noneReason, continue: noneReason };
    }

    const post = endpoint?.method === 'POST' && !form.readOnlyAck;
    const needsPost = post ? labels.postRequired : undefined;
    const empty = emptyPathParams(rows);

    return {
        fetch:
            form.dataSourceId === null
                ? labels.chooseSource
                : endpoint === null
                  ? labels.chooseEndpoint
                  : (needsPost ??
                    (empty.length > 0 ? labels.pathEmpty(empty) : undefined)),
        continue: needsPost,
    };
}

// What Step 2 has loaded: the page owns it (the Continue gate reads it too), the step shows it. `null` is "not loaded yet".
export type SourceCatalog = {
    sources: SourceOption[] | null;
    sourcesError: boolean;
    endpoints: EndpointOption[] | null;
    endpointsError: boolean;
    intervals: number[] | null;
    intervalsLoaded: boolean;
    intervalsError: boolean;
};

export function emptyCatalog(): SourceCatalog {
    return {
        sources: null,
        sourcesError: false,
        endpoints: null,
        endpointsError: false,
        intervals: null,
        intervalsLoaded: false,
        intervalsError: false,
    };
}

// The Endpoint the form has chosen, once the Data Source's Endpoints are loaded.
export function endpointOf(
    catalog: Pick<SourceCatalog, 'endpoints'>,
    form: SourceForm,
): EndpointOption | null {
    return (
        catalog.endpoints?.find((e) => e.endpoint_id === form.endpointId) ??
        null
    );
}

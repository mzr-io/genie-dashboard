import { DATA_SOURCES_URL } from '@/lib/dataSources';
import { xsrfToken } from '@/lib/session';

// Client of the Endpoints API (Story 2.9): `/api/v1/admin/data-sources/{id}/endpoints`. The server owns every rule (the
// method, the path, the bindings, the headers and the body template); the client sends what was typed and shows the
// server's reason as a field error. The Endpoint's `revision` is sent back with an edit.
export const endpointsUrl = (dataSourceId: string): string =>
    `${DATA_SOURCES_URL}/${encodeURIComponent(dataSourceId)}/endpoints`;

export type Method = 'GET' | 'POST';

// Fixed value, Date Range from and to, Block Period Selector start and end, and (Story 2.13) the four user-context bindings:
// the member's ID, email, group, or a defined attribute (whose key id is the row's value).
export type UserBinding =
    | 'user_id'
    | 'user_email'
    | 'user_group'
    | 'user_attribute';

export type Binding =
    | 'fixed'
    | 'date_range_from'
    | 'date_range_to'
    | 'period_start'
    | 'period_end'
    | UserBinding;

// The bindings that are not user context, in the order the Binding select lists them.
export const BINDINGS: Binding[] = [
    'fixed',
    'date_range_from',
    'date_range_to',
    'period_start',
    'period_end',
];

export const USER_BINDINGS: UserBinding[] = [
    'user_id',
    'user_email',
    'user_group',
    'user_attribute',
];

export const isUserBinding = (binding: Binding): binding is UserBinding =>
    (USER_BINDINGS as string[]).includes(binding);

// The text a bound (not fixed) row shows in place of a value: what it will resolve to when the Endpoint is fetched. A
// user-bound row never shows a value, only that it is user context (UX-DR-134).
export const RESOLVED_TEXT: Record<Exclude<Binding, 'fixed'>, string> = {
    date_range_from: 'date_range.from',
    date_range_to: 'date_range.to',
    period_start: 'period.start',
    period_end: 'period.end',
    user_id: 'user context',
    user_email: 'user context',
    user_group: 'user context',
    user_attribute: 'user context',
};

export type BindingRow = {
    name: string;
    binding: Binding;
    value: string | null;
};

export type Param = BindingRow & { kind?: 'path' | 'query' | 'body' };

export type PathSegment =
    | { type: 'literal'; value: string }
    | { type: 'param'; name: string };

export type Endpoint = {
    endpoint_id: string;
    data_source_id: string;
    method: Method;
    path: string;
    path_ast: PathSegment[];
    params: Param[];
    headers: BindingRow[];
    // The template as JSON text, a parameter written {"$param": "name"}.
    body_template: string | null;
    read_only_query: boolean;
    // Derived by the server: true when a parameter or header uses a user binding. `scope_by_caller` is stored for Story 2.14.
    requires_user_context?: boolean;
    scope_by_caller?: boolean;
    // Story 2.14: the Admin's test dates for the date-bound rows (name, `header:{name}` for a header => YYYY-MM-DD) and where the
    // scheduled fetch stands.
    test_values?: Record<string, string>;
    sync?: SyncState;
    revision: number;
    created_at: string;
    updated_at: string;
};

// The scheduled fetch of an Endpoint (Story 2.14): `succeeded` with the time of the last good response (ISO 8601, UTC), `waiting`
// (no successful call yet), or `not_scheduled` with the reason; `missing_test_values` names the date-bound rows without a test date.
export type SyncState = {
    state: 'succeeded' | 'waiting' | 'not_scheduled';
    last_success_at: string | null;
    // Story 2.15: the last attempt (a failed one too) and when the data last changed; a 304 or an equal body moves only the first and `last_success_at`.
    last_checked_at?: string | null;
    payload_changed_at?: string | null;
    reason: 'user_context' | 'test_values' | 'no_interval' | null;
    missing_test_values: string[];
};

export type EndpointList = {
    data: Endpoint[];
    meta: { total: number; matched: number };
};

// What the form sends.
export type EndpointInput = {
    method: Method;
    path: string;
    params: BindingRow[];
    headers: BindingRow[];
    body_template: string | null;
    read_only_query: boolean;
    // The risk confirmation of a POST.
    confirm_read_only: boolean;
    // Only with a user binding (the server refuses it otherwise).
    scope_by_caller?: boolean;
    // Story 2.14: a test date for each date-bound row, by name (`header:{name}` for a header); an empty one is left out.
    test_values?: Record<string, string>;
};

// A request the server refused (or that never arrived: status 0): 403 no permission, 404 not in the Workspace, 409 a
// stale revision (with the `current` Endpoint), 422 with field `errors` and the `reasons`, 429 throttled.
export class EndpointError extends Error {
    constructor(
        readonly status: number,
        readonly code: string | null = null,
        readonly errors: Record<string, string[]> = {},
        readonly reasons: Record<string, string> = {},
        readonly current: Endpoint | null = null,
        readonly requestId: string | null = null,
        // On a 429 from a rate limit: the seconds until the next attempt may be made.
        readonly retryAfter: number | null = null,
    ) {
        super(`endpoint request failed: ${status}`);
    }
}

type Body = {
    data?: unknown;
    meta?: unknown;
    error?: { code?: string; request_id?: string; retry_after?: number };
    errors?: Record<string, string[]>;
    reasons?: Record<string, string>;
    current?: { data?: Endpoint };
};

// The `Retry-After` header of a throttled answer, in whole seconds, when the body did not say.
function retryAfterHeader(response: Response): number | null {
    const value = response.headers?.get?.('Retry-After');

    return value && /^\d{1,6}$/.test(value) ? Number(value) : null;
}

async function call(
    method: 'GET' | 'POST' | 'PUT',
    path: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<Body> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'GET' ? {} : { 'X-XSRF-TOKEN': xsrfToken() }),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new EndpointError(0);
    }

    let json: Body | null = null;

    try {
        json = (await response.json()) as Body | null;
    } catch {
        json = null;
    }

    if (!response.ok || !json) {
        throw new EndpointError(
            response.ok ? 500 : response.status,
            json?.error?.code ?? null,
            json?.errors ?? {},
            json?.reasons ?? {},
            json?.current?.data ?? null,
            json?.error?.request_id ?? null,
            typeof json?.error?.retry_after === 'number'
                ? json.error.retry_after
                : retryAfterHeader(response),
        );
    }

    return json;
}

function isOne(body: Body): body is Body & { data: Endpoint } {
    return (
        !!body.data &&
        typeof body.data === 'object' &&
        !Array.isArray(body.data)
    );
}

export async function fetchEndpoints(
    dataSourceId: string,
    q: string,
    signal?: AbortSignal,
): Promise<EndpointList> {
    const query =
        q.trim() === '' ? '' : `?${new URLSearchParams({ q: q.trim() })}`;
    const body = await call(
        'GET',
        `${endpointsUrl(dataSourceId)}${query}`,
        undefined,
        signal,
    );

    // A malformed answer is a failed load, never an empty list.
    if (!Array.isArray(body.data) || !body.meta) {
        throw new Error('endpoints response is malformed');
    }

    return body as EndpointList;
}

export async function createEndpoint(
    dataSourceId: string,
    input: EndpointInput,
): Promise<Endpoint> {
    const body = await call('POST', endpointsUrl(dataSourceId), input);

    if (!isOne(body)) {
        throw new EndpointError(500);
    }

    return body.data;
}

export async function updateEndpoint(
    dataSourceId: string,
    endpointId: string,
    input: EndpointInput,
    revision: number,
): Promise<Endpoint> {
    const body = await call(
        'PUT',
        `${endpointsUrl(dataSourceId)}/${encodeURIComponent(endpointId)}`,
        { ...input, revision },
    );

    if (!isOne(body)) {
        throw new EndpointError(500);
    }

    return body.data;
}

// The `{name}` placeholders of a path template, in order; what the form adds a parameter row for.
export function pathPlaceholders(path: string): string[] {
    const names: string[] = [];

    for (const match of path.matchAll(
        /\/\{([A-Za-z][A-Za-z0-9_]{0,63})\}(?=\/|$)/g,
    )) {
        if (!names.includes(match[1])) {
            names.push(match[1]);
        }
    }

    return names;
}

// ---- Test an Endpoint (Story 2.10) ----------------------------------------------------------------------------------
// The client sends test values only; the server renders the request from the Endpoint's current revision and checks each
// value with the rules of a save (a 422 names the parameter as `values.{name}`). The Sample Response is read back, for the
// Admin who asked, from the Sample route once the `sample_fetch` Operation has succeeded.
export const HEADER_VALUE_PREFIX = 'header:';

export type TestField = {
    // The key the server expects: the parameter name, or `header:{name}` for a header bound to a date.
    key: string;
    // The name shown on the label.
    name: string;
    // A bound date or period parameter takes a date, a fixed one text.
    type: 'text' | 'date';
    // The stored fixed value is the default; a bound one starts empty.
    initial: string;
    // Whether it fills a path segment, where an empty value stops the test.
    path: boolean;
    // What a bound field resolves to when the Endpoint is fetched, for its hint.
    bound: string | null;
    header: boolean;
};

// One field per parameter, and per header bound to a date or period: a fixed header is sent as stored.
export function testFields(endpoint: Endpoint): TestField[] {
    const fields: TestField[] = [];

    for (const param of endpoint.params) {
        // A user-bound value is never typed: the server resolves it from the chosen member.
        if (isUserBinding(param.binding)) {
            continue;
        }

        fields.push({
            key: param.name,
            name: param.name,
            type: param.binding === 'fixed' ? 'text' : 'date',
            initial: param.binding === 'fixed' ? (param.value ?? '') : '',
            path: param.kind === 'path',
            bound:
                param.binding === 'fixed' ? null : RESOLVED_TEXT[param.binding],
            header: false,
        });
    }

    for (const header of endpoint.headers) {
        if (header.binding !== 'fixed' && !isUserBinding(header.binding)) {
            fields.push({
                key: `${HEADER_VALUE_PREFIX}${header.name}`,
                name: header.name,
                type: 'date',
                initial: '',
                path: false,
                bound: RESOLVED_TEXT[header.binding],
                header: true,
            });
        }
    }

    return fields;
}

export type StartedTest = {
    operation_id: string;
    status: string;
    expires_at: string;
    endpoint_revision: number;
};

// Starts the test (`202` with the Operation to poll). Throws the 422 with its field errors (`values.{name}`), the 429 with
// `retryAfter`, the 404 of an Endpoint that is gone.
export async function startEndpointTest(
    dataSourceId: string,
    endpointId: string,
    values: Record<string, string>,
): Promise<StartedTest> {
    const body = await call(
        'POST',
        `${endpointsUrl(dataSourceId)}/${encodeURIComponent(endpointId)}/test`,
        { values },
    );
    const data = body.data as Partial<StartedTest> | undefined;

    if (!data || typeof data.operation_id !== 'string') {
        throw new EndpointError(500);
    }

    return data as StartedTest;
}

export type Sample = {
    status: number;
    latency_ms: number;
    // The body exactly as received, as text: the only form that keeps every number's lexeme.
    body: string;
    expires_at: string;
};

// The Sample Response of a succeeded test. Throws a 404 for everything that is not the requester's current sample.
export async function fetchSample(
    dataSourceId: string,
    endpointId: string,
    operationId: string,
    signal?: AbortSignal,
): Promise<Sample> {
    const body = await call(
        'GET',
        `${endpointsUrl(dataSourceId)}/${encodeURIComponent(endpointId)}/samples/${encodeURIComponent(operationId)}`,
        undefined,
        signal,
    );
    const data = body.data as Partial<Sample> | undefined;

    if (!data || typeof data.body !== 'string') {
        throw new EndpointError(500);
    }

    return data as Sample;
}

export type BindingOptions = {
    bindings: UserBinding[];
    // The attribute keys the Workspace defines: id and label, never a value.
    attributes: { key_id: string; label: string; value_type: string }[];
    // The active members a Fetch as user can target; empty without `data.preview_as_user`.
    members: { membership_id: string; name: string; email: string }[];
    may_preview: boolean;
};

// What the Binding select lists (Story 2.13), and the members for the Fetch as user picker (`search` narrows them).
export async function fetchBindingOptions(
    dataSourceId: string,
    search = '',
    signal?: AbortSignal,
): Promise<BindingOptions> {
    const query =
        search.trim() === ''
            ? ''
            : `?${new URLSearchParams({ search: search.trim() })}`;
    const body = await call(
        'GET',
        `${DATA_SOURCES_URL}/${encodeURIComponent(dataSourceId)}/binding-options${query}`,
        undefined,
        signal,
    );
    const data = body.data as Partial<BindingOptions> | undefined;

    if (
        !data ||
        !Array.isArray(data.attributes) ||
        !Array.isArray(data.members)
    ) {
        throw new EndpointError(500);
    }

    return data as BindingOptions;
}

// Starts a Fetch as user (`202` with the Operation to poll). The body names the member and the non-bound values only: a
// value for a user-bound name is refused by the server (422 `values.{name}`). Throws the 403, 404, 422 and 429.
export async function startFetchAsUser(
    dataSourceId: string,
    endpointId: string,
    membershipId: string,
    values: Record<string, string>,
): Promise<StartedTest> {
    const body = await call(
        'POST',
        `${endpointsUrl(dataSourceId)}/${encodeURIComponent(endpointId)}/fetch-as-user`,
        { membership: membershipId, values },
    );
    const data = body.data as Partial<StartedTest> | undefined;

    if (!data || typeof data.operation_id !== 'string') {
        throw new EndpointError(500);
    }

    return data as StartedTest;
}

// The date-bound rows of an Endpoint form, as the schedule's test-date fields: one for each parameter or header whose binding
// resolves to a date. The key is what the server expects (`header:{name}` for a header).
export type TestDateField = { key: string; name: string; header: boolean };

export function testDateFields(
    params: BindingRow[],
    headers: BindingRow[],
): TestDateField[] {
    const isDate = (binding: Binding): boolean =>
        binding !== 'fixed' && !isUserBinding(binding);
    const fields: TestDateField[] = [];

    for (const param of params) {
        if (param.name !== '' && isDate(param.binding)) {
            fields.push({ key: param.name, name: param.name, header: false });
        }
    }

    for (const header of headers) {
        if (header.name !== '' && isDate(header.binding)) {
            fields.push({
                key: `${HEADER_VALUE_PREFIX}${header.name}`,
                name: header.name,
                header: true,
            });
        }
    }

    return fields;
}

import type { KpiCardPayload } from '@block-types/kpi-card/v1/payload';
import { xsrfToken } from '@/lib/session';

// Client of the dashboard layout API (Story 4.4, AD-20): `GET /api/v1/dashboards/{id}`. The Inertia page carries only the dashboard id and revision;
// the layout, each item's state and the User-safe Block card come from here. The server clamps geometry and decides every state: nothing here
// re-implements it.
export const dashboardUrl = (id: string): string =>
    `/api/v1/dashboards/${encodeURIComponent(id)}`;

export type HeightPreset = 'small' | 'medium' | 'large';

export type ItemState = 'ok' | 'unpublished' | 'access_removed';

export type DashboardBlock = {
    name: string;
    subtitle: string | null;
    type: string;
    icon: string | null;
    chrome: {
        show_header: boolean;
        show_subtitle: boolean;
        allow_resize: boolean;
        allow_minimize: boolean;
        allow_refresh: boolean;
        show_footer_action: boolean;
    };
    // What "About this block" says; all of it User-safe.
    about: {
        description: string | null;
        source_name: string | null;
        refresh_interval: number | 'live' | null;
        version: string;
        period:
            | 'follow_dashboard'
            | 'own_selector'
            | 'not_date_filtered'
            | null;
    };
    // The footer action, when the Block has one configured (its target is set in a later epic): a label and a URL template or in-app route.
    footer?: { label: string; target: string } | null;
};

export type DashboardItem = {
    item_id: string;
    block_id: string;
    geometry: { col: number; row: number; width: number; height: HeightPreset };
    minimized: boolean;
    state: ItemState;
    refresh_interval: number | 'live' | null;
    is_live: boolean;
    // Null for an item that is not `ok`: no name and no data.
    block: DashboardBlock | null;
};

export type Dashboard = {
    dashboard_id: string;
    name: string;
    is_overview: boolean;
    revision: number;
    items: DashboardItem[];
    next_free_slot: { col: number; row: number };
};

// A request the server refused (or that never arrived: status 0): 401 or 419 the session ended, 404 not this member's dashboard.
export class DashboardError extends Error {
    constructor(readonly status: number) {
        super(`dashboard request failed: ${status}`);
    }
}

// Height presets in 60 px grid units (Small 120 px, Medium 360 px, Large 540 px; UX-DR-149).
export const HEIGHT_UNITS: Record<HeightPreset, number> = {
    small: 2,
    medium: 6,
    large: 9,
};

export async function fetchDashboard(
    id: string,
    signal?: AbortSignal,
): Promise<Dashboard> {
    let response: Response;

    try {
        response = await fetch(dashboardUrl(id), {
            method: 'GET',
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new DashboardError(0);
    }

    let json: { data?: Partial<Dashboard> } | null = null;

    try {
        json = (await response.json()) as { data?: Partial<Dashboard> };
    } catch {
        json = null;
    }

    if (!response.ok) {
        throw new DashboardError(response.status);
    }

    const data = json?.data;

    if (
        !data ||
        typeof data.dashboard_id !== 'string' ||
        !Array.isArray(data.items) ||
        typeof data.revision !== 'number'
    ) {
        throw new DashboardError(500);
    }

    return data as Dashboard;
}

// ---- Results (Story 4.5) --------------------------------------------------------------------------------------------------------------------------

export const resultsUrl = (id: string, range?: string | null): string =>
    `${dashboardUrl(id)}/results${range ? `?range=${encodeURIComponent(range)}` : ''}`;

export type ResultState =
    | 'ok'
    | 'empty'
    | 'unavailable'
    | 'error'
    | 'pending'
    | 'unpublished'
    | 'access_removed';

// One Block's current result as the server serialises it. Measures are decimal strings; nothing here is a float and nothing is HTML. A state
// without data carries a null payload.
export type ItemResult = {
    item_id: string;
    block_id: string;
    state: ResultState;
    stale: boolean;
    refreshing: boolean;
    data_as_of: string | null;
    last_success_at: string | null;
    stale_at: string | null;
    result_etag: string | null;
    slot_states: Record<string, { state: string; reason?: string }>;
    render_payload: Record<string, unknown> | null;
};

export async function fetchResults(
    id: string,
    range: string | null,
    signal?: AbortSignal,
): Promise<ItemResult[]> {
    let response: Response;

    try {
        response = await fetch(resultsUrl(id, range), {
            method: 'GET',
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new DashboardError(0);
    }

    let json: { data?: { items?: ItemResult[] } } | null = null;

    try {
        json = (await response.json()) as { data?: { items?: ItemResult[] } };
    } catch {
        json = null;
    }

    if (!response.ok) {
        throw new DashboardError(response.status);
    }

    if (!json?.data || !Array.isArray(json.data.items)) {
        throw new DashboardError(500);
    }

    return json.data.items;
}

// What the kpi-card Renderer draws for a result (Story 4.5): the stored render payload, with a result that is past its stale time shown as Stale
// since its last successful check. A state without a payload gets the Renderer's own Unavailable or Error card; a miss is null (a skeleton).
export function kpiPayloadOf(result: ItemResult): KpiCardPayload | null {
    const base: KpiCardPayload = {
        state: 'normal',
        title: null,
        subtitle: null,
        icon: null,
        footerAction: null,
        kpiLabel: null,
        headline: { available: false, field: 'headline_value' },
        comparison: null,
        comparisonLabel: null,
        secondaryNote: null,
        statusBadge: null,
        staleSince: null,
        errorMessage: null,
    };

    if (result.state === 'error') {
        return { ...base, state: 'error' };
    }

    if (result.render_payload === null) {
        return result.state === 'unavailable'
            ? { ...base, state: 'unavailable' }
            : null;
    }

    const payload = result.render_payload as unknown as KpiCardPayload;

    return result.stale && payload.state === 'normal'
        ? { ...payload, state: 'stale', staleSince: result.last_success_at }
        : payload;
}

// ---- Chrome commands (Story 4.6) ------------------------------------------------------------------------------------------------------------------

async function send(
    method: 'PUT' | 'POST' | 'DELETE',
    url: string,
    body: Record<string, unknown> | null,
): Promise<{
    status: number;
    json: Record<string, unknown> | null;
    headers: Headers;
}> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: body === null ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new DashboardError(0);
    }

    let json: Record<string, unknown> | null = null;

    try {
        json = (await response.json()) as Record<string, unknown>;
    } catch {
        json = null;
    }

    return { status: response.status, json, headers: response.headers };
}

export type MinimizeOutcome =
    | { ok: true; revision: number }
    // A stale `expected_revision`: the layout has moved on; `revision` is the current one.
    | { ok: false; conflict: true; revision: number | null }
    | { ok: false; conflict: false };

// `SetMinimized` with `expected_revision` (AD-14).
export async function setMinimized(
    dashboardId: string,
    itemId: string,
    minimized: boolean,
    expectedRevision: number,
): Promise<MinimizeOutcome> {
    const { status, json } = await send(
        'PUT',
        `${dashboardUrl(dashboardId)}/items/${encodeURIComponent(itemId)}/minimized`,
        { minimized, expected_revision: expectedRevision },
    );

    if (status === 200) {
        const revision = (json?.data as { revision?: number } | undefined)
            ?.revision;

        return typeof revision === 'number'
            ? { ok: true, revision }
            : { ok: false, conflict: false };
    }

    if (status === 409) {
        const revision = (json?.error as { revision?: number } | undefined)
            ?.revision;

        return {
            ok: false,
            conflict: true,
            revision: typeof revision === 'number' ? revision : null,
        };
    }

    if (status === 401 || status === 419) {
        throw new DashboardError(status);
    }

    return { ok: false, conflict: false };
}

export type RefreshResult =
    | { status: 'requested' }
    // Inside the manual-refresh window: the control waits `retryAfter` seconds.
    | { status: 'limited'; retryAfter: number }
    | { status: 'failed' };

export async function refreshItem(
    dashboardId: string,
    itemId: string,
): Promise<RefreshResult> {
    const { status, json, headers } = await send(
        'POST',
        `${dashboardUrl(dashboardId)}/items/${encodeURIComponent(itemId)}/refresh`,
        null,
    );

    if (status === 202) {
        return { status: 'requested' };
    }

    if (status === 429) {
        const body = (json?.error as { retry_after?: number } | undefined)
            ?.retry_after;
        const header = Number(headers.get('Retry-After'));

        return {
            status: 'limited',
            retryAfter:
                typeof body === 'number' ? body : header > 0 ? header : 30,
        };
    }

    if (status === 401 || status === 419) {
        throw new DashboardError(status);
    }

    return { status: 'failed' };
}

// ---- Add and remove (Story 4.9) -------------------------------------------------------------------------------------------------------------------

export type ItemOutcome =
    | {
          ok: true;
          item: DashboardItem | null;
          revision: number;
          nextFreeSlot: { col: number; row: number } | null;
          changed: boolean;
      }
    // A stale `expected_revision`: nothing changed and the layout has moved on.
    | { ok: false; reason: 'conflict'; revision: number | null }
    // The Block is not available to this member any more (404) or has no published version (422).
    | { ok: false; reason: 'unavailable' }
    // A Mandatory item (403): the reason is `msg:locked`.
    | { ok: false; reason: 'locked' }
    | { ok: false; reason: 'failed' };

function outcomeOf(
    status: number,
    json: Record<string, unknown> | null,
): ItemOutcome {
    if (status === 200 || status === 201) {
        const data = (json?.data ?? {}) as {
            item?: DashboardItem | null;
            revision?: number;
            next_free_slot?: { col: number; row: number } | null;
            removed?: boolean;
        };

        if (typeof data.revision !== 'number') {
            return { ok: false, reason: 'failed' };
        }

        return {
            ok: true,
            item: data.item ?? null,
            revision: data.revision,
            nextFreeSlot: data.next_free_slot ?? null,
            changed: data.removed ?? status === 201,
        };
    }

    if (status === 409) {
        const revision = (json?.error as { revision?: number } | undefined)
            ?.revision;

        return {
            ok: false,
            reason: 'conflict',
            revision: typeof revision === 'number' ? revision : null,
        };
    }

    if (status === 403) {
        return { ok: false, reason: 'locked' };
    }

    if (status === 404 || status === 422) {
        return { ok: false, reason: 'unavailable' };
    }

    if (status === 401 || status === 419) {
        throw new DashboardError(status);
    }

    return { ok: false, reason: 'failed' };
}

// `AddItem{block_id, expected_revision}`: idempotent per Block.
export async function addBlock(
    dashboardId: string,
    blockId: string,
    expectedRevision: number,
): Promise<ItemOutcome> {
    const { status, json } = await send(
        'POST',
        `${dashboardUrl(dashboardId)}/blocks`,
        { block_id: blockId, expected_revision: expectedRevision },
    );

    return outcomeOf(status, json);
}

// `RemoveItem{block_id, expected_revision}`.
export async function removeBlock(
    dashboardId: string,
    blockId: string,
    expectedRevision: number,
): Promise<ItemOutcome> {
    const { status, json } = await send(
        'DELETE',
        `${dashboardUrl(dashboardId)}/blocks/${encodeURIComponent(blockId)}`,
        { expected_revision: expectedRevision },
    );

    return outcomeOf(status, json);
}

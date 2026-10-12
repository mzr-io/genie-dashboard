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

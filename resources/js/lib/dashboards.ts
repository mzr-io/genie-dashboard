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

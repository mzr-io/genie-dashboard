import { xsrfToken } from '@/lib/session';

// Client of the Published blocks API (Story 3.19): `GET /api/v1/admin/blocks/published`. The server owns every rule; the list page only reads.
export const PUBLISHED_BLOCKS_API = '/api/v1/admin/blocks/published';

export type PublishedBlock = {
    block_id: string;
    version_id: string;
    name: string;
    // The Block Type key, such as `kpi-card`.
    type: string;
    category: string | null;
    // The label of the live version, "1.0".
    version: string;
    owner_membership_id: string;
    // The owner's display name; null when it could not be resolved.
    owner: string | null;
    updated_at: string;
    status: 'published';
};

export type PublishedBlockList = {
    data: PublishedBlock[];
    meta: { total: number };
};

// A request the server refused (or that never arrived: status 0): 401 or 419 the session ended, 403 no permission.
export class PublishedBlockError extends Error {
    constructor(readonly status: number) {
        super(`published blocks request failed: ${status}`);
    }
}

export async function fetchPublishedBlocks(
    signal?: AbortSignal,
): Promise<PublishedBlockList> {
    let response: Response;

    try {
        response = await fetch(PUBLISHED_BLOCKS_API, {
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

        throw new PublishedBlockError(0);
    }

    let json: Partial<PublishedBlockList> | null = null;

    try {
        json = (await response.json()) as Partial<PublishedBlockList>;
    } catch {
        json = null;
    }

    if (!response.ok) {
        throw new PublishedBlockError(response.status);
    }

    if (!json || !Array.isArray(json.data) || !json.meta) {
        throw new PublishedBlockError(500);
    }

    return json as PublishedBlockList;
}

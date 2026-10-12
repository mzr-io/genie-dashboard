import { xsrfToken } from '@/lib/session';

// Client of the Add-blocks Panel's list (Story 4.7): `GET /api/v1/blocks/available`. The server decides which Blocks a member may add; the panel
// filters and searches what it was given, and writes every name, description and tag as literal text.
export const AVAILABLE_BLOCKS_API = '/api/v1/blocks/available';

export type CatalogueBlock = {
    block_id: string;
    name: string;
    description: string | null;
    category: string | null;
    type: string;
    data_source: string | null;
    refresh_interval: number | 'live' | null;
    version: string;
    is_new: boolean;
    default_size: { width: number; height: 'small' | 'medium' | 'large' };
};

export class CatalogueError extends Error {
    constructor(readonly status: number) {
        super(`block catalogue request failed: ${status}`);
    }
}

export async function fetchCatalogue(
    signal?: AbortSignal,
): Promise<CatalogueBlock[]> {
    let response: Response;

    try {
        response = await fetch(AVAILABLE_BLOCKS_API, {
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

        throw new CatalogueError(0);
    }

    let json: { data?: CatalogueBlock[] } | null = null;

    try {
        json = (await response.json()) as { data?: CatalogueBlock[] };
    } catch {
        json = null;
    }

    if (!response.ok) {
        throw new CatalogueError(response.status);
    }

    if (!json || !Array.isArray(json.data)) {
        throw new CatalogueError(500);
    }

    return json.data;
}

export type Filters = {
    query: string;
    category: string | null;
    type: string | null;
    source: string | null;
};

export const NO_FILTERS: Filters = {
    query: '',
    category: null,
    type: null,
    source: null,
};

const terms = (query: string): string[] =>
    query
        .toLowerCase()
        .split(/\s+/)
        .filter((term) => term !== '');

// A Block matches when every search term is found in its name, description, category or type; the chips and the filter narrow it further.
export function matches(block: CatalogueBlock, filters: Filters): boolean {
    if (filters.category !== null && block.category !== filters.category) {
        return false;
    }

    if (filters.type !== null && block.type !== filters.type) {
        return false;
    }

    if (filters.source !== null && block.data_source !== filters.source) {
        return false;
    }

    const haystack = [block.name, block.description, block.category, block.type]
        .filter((part): part is string => part !== null)
        .join('\n')
        .toLowerCase();

    return terms(filters.query).every((term) => haystack.includes(term));
}

export type Segment = { text: string; hit: boolean };

// Splits text into plain and matching segments for highlighting. The result is rendered as text nodes, never as markup.
export function highlight(text: string, query: string): Segment[] {
    const wanted = terms(query);

    if (wanted.length === 0 || text === '') {
        return [{ text, hit: false }];
    }

    const lower = text.toLowerCase();
    const marks = Array.from({ length: text.length }, () => false);

    for (const term of wanted) {
        let at = lower.indexOf(term);

        while (at !== -1) {
            for (let i = at; i < at + term.length; i++) {
                marks[i] = true;
            }

            at = lower.indexOf(term, at + term.length);
        }
    }

    const out: Segment[] = [];

    for (let i = 0; i < text.length; i++) {
        const last = out[out.length - 1];

        if (last !== undefined && last.hit === marks[i]) {
            last.text += text[i];
        } else {
            out.push({ text: text[i], hit: marks[i] });
        }
    }

    return out;
}

// The categories that have at least one available Block, in alphabetical order.
export function categoriesOf(blocks: CatalogueBlock[]): string[] {
    return Array.from(
        new Set(
            blocks
                .map((block) => block.category)
                .filter((name): name is string => name !== null),
        ),
    ).sort((a, b) => a.localeCompare(b));
}

// ---- Inline preview with sample values (Story 4.8) ------------------------------------------------------------------------------------------------

export type BlockSample = {
    block_id: string;
    type: string;
    version: string;
    // The Block's render payload with values generated from its field snapshot and presentation; never data from a source.
    render_payload: Record<string, unknown>;
};

export const sampleUrl = (blockId: string): string =>
    `/api/v1/blocks/${encodeURIComponent(blockId)}/sample`;

// The generated sample of one Block, or null when none could be made (the panel then shows a labelled placeholder).
export async function fetchSample(
    blockId: string,
    signal?: AbortSignal,
): Promise<BlockSample | null> {
    try {
        const response = await fetch(sampleUrl(blockId), {
            method: 'GET',
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });

        if (!response.ok) {
            return null;
        }

        const json = (await response.json()) as { data?: BlockSample };

        return json.data !== undefined &&
            typeof json.data.type === 'string' &&
            typeof json.data.render_payload === 'object'
            ? json.data
            : null;
    } catch {
        return null;
    }
}

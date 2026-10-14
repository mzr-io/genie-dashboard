import { DRAFT_BLOCKS_URL, DraftBlockError } from '@/lib/draftBlocks';

// The diff of a Draft against the published version (Story 6.3): `GET /api/v1/admin/blocks/drafts/{draft}/diff`. The server owns it; this is its shape
// and the call. Four sections, each as before and after pairs; a first publish is not diffed.
export const DIFF_SECTIONS = [
    'mapping',
    'presentation',
    'chrome',
    'endpoint',
] as const;
export type DiffSection = (typeof DIFF_SECTIONS)[number];

export type DiffChange = {
    path: string;
    before: string | null;
    after: string | null;
};

export type VersionDiff = {
    first_publish: boolean;
    current_version: string | null;
    draft_version: string;
    sections: Record<DiffSection, DiffChange[]>;
    summary: Partial<Record<DiffSection, number>>;
};

export async function fetchVersionDiff(
    draftId: string,
    signal?: AbortSignal,
): Promise<VersionDiff> {
    let response: Response;

    try {
        response = await fetch(`${DRAFT_BLOCKS_URL}/${draftId}/diff`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
    } catch {
        throw new DraftBlockError(0);
    }

    if (!response.ok) {
        throw new DraftBlockError(response.status);
    }

    return ((await response.json()) as { data: VersionDiff }).data;
}

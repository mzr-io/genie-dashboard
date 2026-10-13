import type { SampleClaim } from '@/lib/blockSample';
import { wizardCall } from '@/lib/blockWizard';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';

// The Text Templates of a Draft (Story 5.4; FR-26): a map from a text slot to its source text, stored by the server as `config.templates` with the
// grammar judged there. Tokens: `{field}`, `{field|format}`, `{ROW_COUNT}`, `{period.start|MMM d}`, `{period.end|MMM d, yyyy}`, `{user.name}`.
export type TemplateSources = Record<string, string>;

export type StoredTemplates = Record<
    string,
    { source: string; parts: unknown[] }
>;

export type TemplateFault = {
    slot: string;
    field: string;
    reason: 'field_missing';
};

export type TemplatesWrite = {
    templates: StoredTemplates;
    draft_revision: number;
    query_plan: unknown;
};

export const FIELD_FORMATS = [
    'text',
    'number',
    'integer',
    'currency',
    'percent',
    'compact',
    'date',
] as const;

export const DATE_PATTERNS = [
    'MMM d',
    'MMM d, yyyy',
    'MMMM yyyy',
    'MMM yyyy',
    'd MMM',
    'EEE, MMM d',
    'yyyy-MM-dd',
] as const;

export const TEMPLATE_MAX = 300;

export function sourcesOf(
    stored: StoredTemplates | undefined,
): TemplateSources {
    return Object.fromEntries(
        Object.entries(stored ?? {}).map(([slot, t]) => [slot, t.source]),
    );
}

// `PUT .../mapping/templates`: every template, whole; `{}` clears them. A 422 `blocks.invalid_config` names each fault as
// `config.templates.<slot>.source` with the token and its position.
export async function putTemplates(
    draftId: string,
    templates: TemplateSources,
    revision: number,
    claim: SampleClaim,
): Promise<TemplatesWrite> {
    const answer = await wizardCall(
        'PUT',
        `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/mapping/templates`,
        { templates, draft_revision: revision, ...claim },
    );

    return answer.data as TemplatesWrite;
}

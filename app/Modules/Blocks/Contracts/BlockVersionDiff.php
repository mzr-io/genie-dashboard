<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The difference between a Draft and the version users have now (Story 6.3), in the four sections Mapping, Presentation, Chrome and Endpoint. A first
 * publish has nothing to compare with and is not diffed.
 */
interface BlockVersionDiff
{
    /**
     * @return array{first_publish: bool, current_version: string|null, draft_version: string, sections: array<string, list<array{path: string, before: string|null, after: string|null}>>, summary: array<string, int>}
     *
     * @throws DraftNotFound
     */
    public function forDraft(string $workspaceId, string $draftId): array;
}

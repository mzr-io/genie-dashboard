<?php

namespace App\Modules\Blocks\Contracts;

/** The published face of Blocks for dashboards (Story 4.4). Read-only, in the caller's Workspace transaction. */
interface BlockCards
{
    /**
     * @param  list<string>  $blockIds
     * @return array<string, BlockCard> by Block id (lower case); a Block that does not exist or has no published current version is absent
     */
    public function forBlocks(string $workspaceId, array $blockIds): array;

    /** Whether the Workspace has such a Block at all, published or not (a caller telling "unpublished" from "unknown"). */
    public function exists(string $workspaceId, string $blockId): bool;
}

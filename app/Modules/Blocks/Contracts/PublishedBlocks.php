<?php

namespace App\Modules\Blocks\Contracts;

/** The Workspace's published Blocks (Story 3.19, the minimal Published list; publish impact, restore and unpublish are Epic 6). */
interface PublishedBlocks
{
    /** @return list<PublishedBlock> the Blocks with a published current version, newest update first */
    public function list(string $workspaceId): array;
}

<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Who is affected by a change to a Block (Story 6.2): the distinct members who have it on a dashboard (a member with it on three dashboards is one) and
 * the Templates that include it. Read from Blocks' own `block_usage`, inside the Workspace's transaction, so another Workspace's Block counts nothing.
 */
interface BlockImpact
{
    /**
     * @return array{users: int, templates: list<string>} the Templates are empty until Templates exist
     */
    public function forBlock(string $workspaceId, string $blockId): array;
}

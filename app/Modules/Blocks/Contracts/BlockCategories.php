<?php

namespace App\Modules\Blocks\Contracts;

/** The categories of the Workspace's Blocks (Story 3.4). Runs in the Workspace's transaction under row-level security. */
interface BlockCategories
{
    /** The name of the category every Workspace has, created lazily the first time categories are read. */
    public const GENERAL = 'General';

    /**
     * The Workspace's categories, name order. Creates "General" first when it is missing (idempotent), and gives it to every Block
     * that has no category yet.
     *
     * @return list<Category>
     */
    public function all(string $workspaceId): array;
}

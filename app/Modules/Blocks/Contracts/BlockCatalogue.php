<?php

namespace App\Modules\Blocks\Contracts;

/** The Blocks a member may add to a dashboard (Story 4.7). Read-only, in the caller's Workspace transaction. */
interface BlockCatalogue
{
    /**
     * Every published Block of the Workspace, newest publish first. Access is not applied here: the caller filters with Access, which owns it.
     *
     * @return list<CatalogueBlock>
     */
    public function published(string $workspaceId): array;

    /** The seconds a Block counts as "New" after its publish; null while the `pending_input` setting is unset (nothing is new). */
    public function newWindowSeconds(): ?int;
}

<?php

namespace App\Modules\Blocks\Contracts;

/** The Admin who changes a Draft: their active membership in the session's Workspace. */
final readonly class DraftActor
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
    ) {}
}

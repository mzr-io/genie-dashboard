<?php

namespace App\Modules\Connector\Contracts;

/** The Admin who changes the allowlist: their active membership in the session's Workspace. */
final readonly class AllowlistActor
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
    ) {}
}

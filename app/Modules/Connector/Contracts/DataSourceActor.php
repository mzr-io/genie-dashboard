<?php

namespace App\Modules\Connector\Contracts;

/** The Admin who changes a Data Source: their active membership in the session's Workspace. */
final readonly class DataSourceActor
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
    ) {}
}

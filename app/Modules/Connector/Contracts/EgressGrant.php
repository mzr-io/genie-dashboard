<?php

namespace App\Modules\Connector\Contracts;

/** A stored or revoked private-range grant. `mirrored` is false when the Workspace audit mirror failed after the commit. */
final readonly class EgressGrant
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $cidr,
        public bool $mirrored,
    ) {}
}

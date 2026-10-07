<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\EgressGrant;
use App\Modules\Connector\Contracts\EgressGrants;
use LogicException;

final class FakeGrants implements EgressGrants
{
    /** @param  array<string, list<string>>  $active  Workspace ID => CIDRs */
    public function __construct(public array $active = []) {}

    public function activeCidrs(string $workspaceId): array
    {
        return $this->active[$workspaceId] ?? [];
    }

    public function grant(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant
    {
        throw new LogicException('not used');
    }

    public function revoke(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant
    {
        throw new LogicException('not used');
    }
}

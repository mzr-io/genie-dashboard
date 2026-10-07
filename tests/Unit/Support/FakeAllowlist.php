<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\AddedHost;
use App\Modules\Connector\Contracts\AllowedHost;
use App\Modules\Connector\Contracts\AllowlistActor;
use App\Modules\Connector\Contracts\AllowlistPage;
use App\Modules\Connector\Contracts\AllowlistQuery;
use App\Modules\Connector\Contracts\HostAllowlist;
use LogicException;

/** An allowlist held in memory: `host:port` strings per Workspace. */
final class FakeAllowlist implements HostAllowlist
{
    /** @param  array<string, list<string>>  $entries  Workspace ID => ['host:port', ...] */
    public function __construct(public array $entries = []) {}

    public function isAllowed(string $workspaceId, string $scheme, string $host, int $port): bool
    {
        // An entry's scheme follows its port: 80 is http, every other port https.
        if ($scheme !== ($port === 80 ? 'http' : 'https')) {
            return false;
        }

        return in_array("{$host}:{$port}", $this->entries[$workspaceId] ?? [], true);
    }

    public function list(string $workspaceId, AllowlistQuery $query): AllowlistPage
    {
        throw new LogicException('not used');
    }

    public function add(AllowlistActor $actor, AllowedHost $host, int $revision): AddedHost
    {
        throw new LogicException('not used');
    }

    public function remove(AllowlistActor $actor, string $entryId, int $revision): int
    {
        throw new LogicException('not used');
    }

    public function dependents(string $workspaceId, string $entryId): array
    {
        return [];
    }
}

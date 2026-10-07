<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\DependentDataSource;
use App\Modules\Connector\Contracts\HostAllowlistDependents;
use Illuminate\Support\Facades\DB;

/** The Data Sources whose base URL uses an allowlisted host and port (Story 2.3), for the removal dialog. Runs in the caller's Workspace transaction. */
final class SqlHostAllowlistDependents implements HostAllowlistDependents
{
    public function dependentsOf(string $workspaceId, string $host, int $port): array
    {
        /** @var list<object{id: string, name: string}> $rows */
        $rows = DB::select(
            'select id, name from data_sources where workspace_id = ? and host = ? and port = ? order by lower(name), id',
            [$workspaceId, $host, $port],
        );

        return array_map(fn (object $row): DependentDataSource => new DependentDataSource(strtolower($row->id), $row->name), $rows);
    }
}

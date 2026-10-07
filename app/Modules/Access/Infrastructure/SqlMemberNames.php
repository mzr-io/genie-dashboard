<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\MemberNames;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reads display names from the Access view `access_workspace_members` (row-level security of the caller applies). */
final class SqlMemberNames implements MemberNames
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function names(string $workspaceId, array $membershipIds): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), array_filter($membershipIds, Str::isUuid(...)))));

        if ($ids === []) {
            return [];
        }

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $ids): array {
            $marks = implode(', ', array_fill(0, count($ids), '?'));

            /** @var list<object{id: string, name: string}> $rows */
            $rows = DB::select("SELECT m.id, m.name FROM access_workspace_members m WHERE m.workspace_id = ? AND m.id IN ({$marks})", [$workspaceId, ...$ids]);

            $names = [];

            foreach ($rows as $row) {
                $names[strtolower($row->id)] = $row->name;
            }

            return $names;
        });
    }
}

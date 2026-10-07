<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\GroupDirectory;
use App\Modules\Access\Contracts\GroupMemberRow;
use App\Modules\Access\Contracts\GroupPage;
use App\Modules\Access\Contracts\GroupQuery;
use App\Modules\Access\Contracts\GroupRow;
use App\Modules\Access\Contracts\GroupSort;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the Workspace's groups from `user_groups` and `group_members` (row-level security of the caller applies, and
 * the Workspace ID is also a plain filter) and the members' names from the Access view `access_workspace_members`.
 * The list is small by nature (a Workspace's groups), so it is returned whole, sorted by a whitelisted column.
 */
final class SqlGroupDirectory implements GroupDirectory
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function list(string $workspaceId, GroupQuery $query): GroupPage
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $query): GroupPage {
            $pattern = $this->pattern($query->search);
            $match = $pattern === null ? 'true' : "lower(g.name) LIKE lower(?) ESCAPE '!'";
            $matchBindings = $pattern === null ? [] : [$pattern];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                "SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM user_groups g WHERE g.workspace_id = ?",
                [...$matchBindings, $workspaceId],
            );

            $order = $query->descending ? 'DESC' : 'ASC';
            $key = match ($query->sort) {
                GroupSort::Name => 'lower(g.name)',
                GroupSort::Members => '(SELECT count(*) FROM group_members c WHERE c.group_id = g.id)',
                GroupSort::Created => 'g.created_at',
            };

            /** @var list<object{id: string, name: string, created: string, updated: string}> $found */
            $found = DB::select(
                'SELECT g.id, g.name, to_char(g.created_at, '.self::STAMP.') AS created, to_char(g.updated_at, '.self::STAMP.') AS updated '
                ."FROM user_groups g WHERE g.workspace_id = ? AND {$match} ORDER BY {$key} {$order}, g.id {$order}",
                [$workspaceId, ...$matchBindings],
            );

            return new GroupPage($this->withMembers($workspaceId, $found), (int) $counts->total, (int) $counts->matched);
        });
    }

    public function find(string $workspaceId, string $groupId): ?GroupRow
    {
        if (! Str::isUuid($groupId)) {
            return null;
        }

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $groupId): ?GroupRow {
            /** @var object{id: string, name: string, created: string, updated: string}|null $found */
            $found = DB::selectOne(
                'SELECT g.id, g.name, to_char(g.created_at, '.self::STAMP.') AS created, to_char(g.updated_at, '.self::STAMP.') AS updated '
                .'FROM user_groups g WHERE g.workspace_id = ? AND g.id = ?',
                [$workspaceId, strtolower($groupId)],
            );

            return $found === null ? null : $this->withMembers($workspaceId, [$found])[0];
        });
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }

    /**
     * @param  list<object{id: string, name: string, created: string, updated: string}>  $found
     * @return list<GroupRow>
     */
    private function withMembers(string $workspaceId, array $found): array
    {
        $ids = array_map(fn (object $row): string => strtolower($row->id), $found);
        $members = [];

        if ($ids !== []) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));

            /** @var list<object{group_id: string, membership_id: string, name: string, email: string, status: string}> $rows */
            $rows = DB::select(
                'SELECT gm.group_id, gm.membership_id, m.name, m.email, m.status '
                .'FROM group_members gm JOIN access_workspace_members m ON m.id = gm.membership_id AND m.workspace_id = gm.workspace_id '
                ."WHERE gm.workspace_id = ? AND gm.group_id IN ({$marks}) ORDER BY lower(m.name), lower(m.email), gm.membership_id",
                [$workspaceId, ...$ids],
            );

            foreach ($rows as $row) {
                $members[strtolower($row->group_id)][] = new GroupMemberRow(strtolower($row->membership_id), $row->name, $row->email, $row->status);
            }
        }

        return array_map(fn (object $row): GroupRow => new GroupRow(
            id: strtolower($row->id),
            name: $row->name,
            memberCount: count($members[strtolower($row->id)] ?? []),
            members: $members[strtolower($row->id)] ?? [],
            createdAt: $row->created,
            updatedAt: $row->updated,
        ), $found);
    }
}

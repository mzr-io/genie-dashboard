<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\InvalidMemberCursor;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberPage;
use App\Modules\Access\Contracts\MemberQuery;
use App\Modules\Access\Contracts\MemberRow;
use App\Modules\Access\Contracts\MemberSort;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the list from the Access view `access_workspace_members` (row-level security of the caller applies) and
 * the SECURITY DEFINER function `access_workspace_invitations()` (scoped by the transaction's Workspace setting).
 * Both run inside the Workspace's transaction, and the Workspace ID is also a plain filter on the members.
 *
 * Paging is by cursor (a keyset over the sort key and the row ID, so the order is stable and no offset is
 * used). Sort keys are never null and compare in the `C` collation, so the order and the cursor agree.
 */
final class SqlMemberDirectory implements MemberDirectory
{
    private const SELECT_ROWS = <<<'SQL'
        SELECT m.id, 'member'::text AS kind, m.name, m.email, m.role, m.status, m.last_active_at, m.revision
        FROM access_workspace_members m
        WHERE m.workspace_id = ?
        UNION ALL
        SELECT i.id, 'invitation'::text AS kind, ''::varchar AS name, i.email, i.role, 'invited'::text AS status, NULL::timestamp AS last_active_at, NULL::integer AS revision
        FROM access_workspace_invitations() i
        SQL;

    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function page(string $workspaceId, MemberQuery $query): MemberPage
    {
        $cursor = $query->cursor === null ? null : $this->decode($query);

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $query, $cursor): MemberPage {
            $pattern = $this->pattern($query->search);
            $match = $pattern === null ? 'true' : "(lower(d.name) LIKE lower(?) ESCAPE '!' OR lower(d.email) LIKE lower(?) ESCAPE '!')";
            $matchBindings = $pattern === null ? [] : [$pattern, $pattern];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                'WITH directory AS ('.self::SELECT_ROWS.") SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM directory d",
                [$workspaceId, ...$matchBindings],
            );

            $order = $query->descending ? 'DESC' : 'ASC';
            $after = '';
            $afterBindings = [];

            if ($cursor !== null) {
                $after = 'AND (k.sort_key, k.id) '.($query->descending ? '<' : '>').' (?::text COLLATE "C", ?::uuid)';
                $afterBindings = [$cursor['key'], $cursor['id']];
            }

            $limit = max(1, $query->pageSize);

            /** @var list<object{id: string, kind: string, name: string, email: string, role: string, status: string, last_active: string|null, revision: int|string|null, sort_key: string}> $found */
            $found = DB::select(
                'WITH directory AS ('.self::SELECT_ROWS.'), keyed AS ('
                .'SELECT d.*, ('.$this->sortExpression($query->sort).')::text COLLATE "C" AS sort_key FROM directory d WHERE '.$match
                .') SELECT k.id, k.kind, k.name, k.email, k.role, k.status, k.revision, '
                ."to_char(k.last_active_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS last_active, k.sort_key "
                ."FROM keyed k WHERE true {$after} ORDER BY k.sort_key {$order}, k.id {$order} LIMIT ".($limit + 1),
                [$workspaceId, ...$matchBindings, ...$afterBindings],
            );

            $more = count($found) > $limit;
            $found = array_slice($found, 0, $limit);
            $last = $found === [] ? null : $found[array_key_last($found)];

            return new MemberPage(
                rows: $this->withPermissions($found),
                nextCursor: $more && $last !== null ? $this->encode($query, $last->sort_key, $last->id) : null,
                total: (int) $counts->total,
                matched: (int) $counts->matched,
            );
        });
    }

    public function find(string $workspaceId, string $membershipId): ?MemberRow
    {
        if (! Str::isUuid($membershipId)) {
            return null;
        }

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $membershipId): ?MemberRow {
            /** @var object{id: string, kind: string, name: string, email: string, role: string, status: string, last_active: string|null, revision: int|string|null}|null $found */
            $found = DB::selectOne(
                "SELECT m.id, 'member'::text AS kind, m.name, m.email, m.role, m.status, m.revision, "
                ."to_char(m.last_active_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS last_active "
                .'FROM access_workspace_members m WHERE m.workspace_id = ? AND m.id = ?',
                [$workspaceId, strtolower($membershipId)],
            );

            return $found === null ? null : $this->withPermissions([$found])[0];
        });
    }

    /** The sort key of a row: never null, lower case for text so the order ignores case. */
    private function sortExpression(MemberSort $sort): string
    {
        return match ($sort) {
            MemberSort::Name => 'lower(d.name)',
            MemberSort::Email => 'lower(d.email)',
            MemberSort::Role => 'd.role',
            MemberSort::Status => 'd.status',
            MemberSort::LastActive => "coalesce(to_char(d.last_active_at, 'YYYYMMDDHH24MISSUS'), '')",
        };
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }

    /**
     * The rows with the permissions of their members (one query, inside the Workspace's transaction).
     *
     * @param  list<object{id: string, kind: string, name: string, email: string, role: string, status: string, last_active: string|null, revision?: int|string|null}>  $found
     * @return list<MemberRow>
     */
    private function withPermissions(array $found): array
    {
        $ids = array_values(array_map(fn (object $row): string => $row->id, array_filter($found, fn (object $row): bool => $row->kind === MemberRow::MEMBER)));
        $held = [];

        if ($ids !== []) {
            foreach (DB::table('membership_permissions')->whereIn('membership_id', $ids)->orderBy('permission')->get(['membership_id', 'permission']) as $permission) {
                $held[strtolower((string) $permission->membership_id)][] = (string) $permission->permission;
            }
        }

        return array_map(fn (object $row): MemberRow => $this->row($row, $held[strtolower($row->id)] ?? []), $found);
    }

    /**
     * @param  object{id: string, kind: string, name: string, email: string, role: string, status: string, last_active: string|null, revision?: int|string|null}  $found
     * @param  list<string>  $permissions
     */
    private function row(object $found, array $permissions = []): MemberRow
    {
        return new MemberRow(
            id: $found->id,
            kind: $found->kind,
            name: $found->name,
            email: $found->email,
            role: $found->role,
            status: $found->status,
            groups: [],
            lastActiveAt: $found->last_active,
            permissions: $permissions,
            revision: isset($found->revision) ? (int) $found->revision : null,
        );
    }

    private function encode(MemberQuery $query, string $key, string $id): string
    {
        $json = json_encode(['s' => $query->sort->value, 'd' => $query->descending, 'k' => $key, 'i' => $id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{key: string, id: string}
     *
     * @throws InvalidMemberCursor
     */
    private function decode(MemberQuery $query): array
    {
        $raw = base64_decode(strtr((string) $query->cursor, '-_', '+/'), true);
        $data = $raw === false ? null : json_decode($raw, true);

        if (
            ! is_array($data)
            || ($data['s'] ?? null) !== $query->sort->value
            || ($data['d'] ?? null) !== $query->descending
            || ! is_string($data['k'] ?? null)
            || str_contains($data['k'], "\0")
            || ! mb_check_encoding($data['k'], 'UTF-8')
            || ! is_string($data['i'] ?? null)
            || ! Str::isUuid($data['i'])
        ) {
            throw new InvalidMemberCursor;
        }

        return ['key' => $data['k'], 'id' => strtolower($data['i'])];
    }
}

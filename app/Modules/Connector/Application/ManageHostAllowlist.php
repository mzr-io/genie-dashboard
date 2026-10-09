<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AddedHost;
use App\Modules\Connector\Contracts\AllowedHost;
use App\Modules\Connector\Contracts\AllowlistActor;
use App\Modules\Connector\Contracts\AllowlistPage;
use App\Modules\Connector\Contracts\AllowlistQuery;
use App\Modules\Connector\Contracts\AllowlistRevisionConflict;
use App\Modules\Connector\Contracts\AllowlistSort;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\HostAllowlistDependents;
use App\Modules\Connector\Contracts\HostAlreadyAllowed;
use App\Modules\Connector\Contracts\HostEntry;
use App\Modules\Connector\Contracts\HostEntryNotFound;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and changes the Workspace host allowlist (Story 2.1) inside the Workspace's transaction.
 *
 * Every change first locks the Workspace's `host_allowlist_versions` row (creating it on first use), so changes are
 * serialised; it compares the caller's revision, applies the change, bumps the revision and writes the audit event in
 * the same transaction. A stale revision throws with the current list. Removal goes through a SECURITY DEFINER
 * function (role `app` has no DELETE on tenant tables). Nothing here calls, resolves or connects to any host.
 */
final class ManageHostAllowlist implements HostAllowlist
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly HostAllowlistDependents $dependents,
    ) {}

    public function list(string $workspaceId, AllowlistQuery $query): AllowlistPage
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $query): AllowlistPage {
            // The revision first: a change committed meanwhile can then only make the rows newer than the revision, never older.
            $revision = $this->revision($workspaceId);
            $pattern = $this->pattern($query->search);
            $match = $pattern === null ? 'true' : "e.host LIKE lower(?) ESCAPE '!'";
            $bindings = $pattern === null ? [] : [$pattern];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                "SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM host_allowlist_entries e WHERE e.workspace_id = ?",
                [...$bindings, $workspaceId],
            );

            $order = $query->descending ? 'DESC' : 'ASC';
            $key = match ($query->sort) {
                AllowlistSort::Host => 'e.host',
                AllowlistSort::Scheme => 'e.scheme',
                AllowlistSort::Port => 'e.port',
                AllowlistSort::Added => 'e.created_at',
            };

            /** @var list<object{id: string, host: string, scheme: string, port: int|string, added_by: string, created: string}> $found */
            $found = DB::select(
                'SELECT e.id, e.host, e.scheme, e.port, e.added_by_membership_id AS added_by, to_char(e.created_at, '.self::STAMP.') AS created '
                ."FROM host_allowlist_entries e WHERE e.workspace_id = ? AND {$match} ORDER BY {$key} {$order}, e.host {$order}, e.port {$order}, e.id {$order}",
                [$workspaceId, ...$bindings],
            );

            return new AllowlistPage(
                array_map($this->entry(...), $found),
                $revision,
                (int) $counts->total,
                (int) $counts->matched,
            );
        });
    }

    public function isAllowed(string $workspaceId, string $scheme, string $host, int $port): bool
    {
        return $this->transactions->run($workspaceId, fn (): bool => DB::selectOne(
            'select 1 as found from host_allowlist_entries where workspace_id = ? and scheme = ? and host = ? and port = ? limit 1',
            [$workspaceId, $scheme, $host, $port],
        ) !== null);
    }

    public function add(AllowlistActor $actor, AllowedHost $host, int $revision): AddedHost
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $host, $revision): AddedHost {
            $current = $this->lockVersion($actor->workspaceId);
            $this->assertCurrent($actor->workspaceId, $current, $revision);

            $taken = DB::selectOne(
                'select 1 as taken from host_allowlist_entries where workspace_id = ? and host = ? and port = ? limit 1',
                [$actor->workspaceId, $host->host, $host->port],
            );

            if ($taken !== null) {
                throw new HostAlreadyAllowed;
            }

            $id = (string) Str::uuid7();

            try {
                DB::insert(
                    'insert into host_allowlist_entries (id, workspace_id, host, scheme, port, added_by_membership_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $actor->workspaceId, $host->host, $host->scheme, $host->port, $actor->membershipId, now(), now()],
                );
            } catch (QueryException $e) {
                // A concurrent add of the same host and port cannot get here (the version lock serialises), but the unique index still guards it.
                throw ($e->errorInfo[0] ?? null) === '23505' ? new HostAlreadyAllowed : $e;
            }

            $next = $this->bump($actor->workspaceId);

            $this->audit->record(
                AuditAction::ConnectorHostAllowlistEntryCreated,
                ['entry_id' => $id, 'host' => $host->host, 'scheme' => $host->scheme, 'port' => $host->port],
                subject: 'host_allowlist_entry:'.$id,
                actor: $actor->membershipId,
            );

            /** @var object{id: string, host: string, scheme: string, port: int|string, added_by: string, created: string} $row */
            $row = DB::selectOne(
                'SELECT e.id, e.host, e.scheme, e.port, e.added_by_membership_id AS added_by, to_char(e.created_at, '.self::STAMP.') AS created FROM host_allowlist_entries e WHERE e.id = ?',
                [$id],
            );

            return new AddedHost($this->entry($row), $next);
        });
    }

    public function remove(AllowlistActor $actor, string $entryId, int $revision): int
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $entryId, $revision): int {
            $current = $this->lockVersion($actor->workspaceId);
            $entry = $this->find($actor->workspaceId, $entryId);

            if ($entry === null) {
                throw new HostEntryNotFound;
            }

            $this->assertCurrent($actor->workspaceId, $current, $revision);

            /** @var object{removed: bool|string|int} $row */
            $row = DB::selectOne('select connector_remove_host_allowlist_entry(?::uuid) as removed', [$entry->id]);

            if (! filter_var($row->removed, FILTER_VALIDATE_BOOLEAN)) {
                throw new HostEntryNotFound;
            }

            $next = $this->bump($actor->workspaceId);

            $this->audit->record(
                AuditAction::ConnectorHostAllowlistEntryRemoved,
                ['entry_id' => $entry->id, 'host' => $entry->host, 'scheme' => $entry->scheme, 'port' => $entry->port],
                subject: 'host_allowlist_entry:'.$entry->id,
                actor: $actor->membershipId,
            );

            return $next;
        });
    }

    public function dependents(string $workspaceId, string $entryId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $entryId): array {
            $entry = $this->find($workspaceId, $entryId) ?? throw new HostEntryNotFound;

            return $this->dependents->dependentsOf($workspaceId, $entry->host, $entry->port);
        });
    }

    private function find(string $workspaceId, string $entryId): ?HostEntry
    {
        if (! Str::isUuid($entryId)) {
            return null;
        }

        /** @var object{id: string, host: string, scheme: string, port: int|string, added_by: string, created: string}|null $row */
        $row = DB::selectOne(
            'SELECT e.id, e.host, e.scheme, e.port, e.added_by_membership_id AS added_by, to_char(e.created_at, '.self::STAMP.') AS created '
            .'FROM host_allowlist_entries e WHERE e.workspace_id = ? AND e.id = ?',
            [$workspaceId, strtolower($entryId)],
        );

        return $row === null ? null : $this->entry($row);
    }

    /** The list's revision (0 for a list that has never changed). */
    private function revision(string $workspaceId): int
    {
        /** @var object{revision: int|string}|null $row */
        $row = DB::selectOne('select revision from host_allowlist_versions where workspace_id = ?', [$workspaceId]);

        return $row === null ? 0 : (int) $row->revision;
    }

    /** Creates the version row on first use and locks it for the rest of the transaction; returns the revision. */
    private function lockVersion(string $workspaceId): int
    {
        DB::insert(
            'insert into host_allowlist_versions (workspace_id, revision, created_at, updated_at) values (?, 0, ?, ?) on conflict (workspace_id) do nothing',
            [$workspaceId, now(), now()],
        );

        /** @var object{revision: int|string} $row */
        $row = DB::selectOne('select revision from host_allowlist_versions where workspace_id = ? for update', [$workspaceId]);

        return (int) $row->revision;
    }

    private function bump(string $workspaceId): int
    {
        /** @var object{revision: int|string} $row */
        $row = DB::selectOne(
            'update host_allowlist_versions set revision = revision + 1, updated_at = ? where workspace_id = ? returning revision',
            [now(), $workspaceId],
        );

        return (int) $row->revision;
    }

    private function assertCurrent(string $workspaceId, int $current, int $given): void
    {
        if ($current !== $given) {
            throw new AllowlistRevisionConflict($this->list($workspaceId, new AllowlistQuery));
        }
    }

    /** @param  object{id: string, host: string, scheme: string, port: int|string, added_by: string, created: string}  $row */
    private function entry(object $row): HostEntry
    {
        return new HostEntry(strtolower($row->id), $row->host, $row->scheme, (int) $row->port, strtolower($row->added_by), $row->created);
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}

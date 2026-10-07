<?php

namespace App\Modules\Connector\Contracts;

/**
 * The Workspace host allowlist (Story 2.1), in the caller's Workspace transaction. Every change runs under a row lock
 * on the list's version, compares the caller's revision (a stale one is an {@see AllowlistRevisionConflict}), bumps
 * it, and writes `connector.host_allowlist_entry.created` or `.removed` to the audit log in the same transaction.
 * Nothing here calls, resolves or connects to any host.
 */
interface HostAllowlist
{
    public function list(string $workspaceId, AllowlistQuery $query): AllowlistPage;

    /**
     * @return AddedHost the new entry and the list's new revision
     *
     * @throws HostAlreadyAllowed
     * @throws AllowlistRevisionConflict
     */
    public function add(AllowlistActor $actor, AllowedHost $host, int $revision): AddedHost;

    /**
     * @return int the list's new revision
     *
     * @throws HostEntryNotFound
     * @throws AllowlistRevisionConflict
     */
    public function remove(AllowlistActor $actor, string $entryId, int $revision): int;

    /**
     * The Data Sources that removing the entry would block.
     *
     * @return list<DependentDataSource>
     *
     * @throws HostEntryNotFound
     */
    public function dependents(string $workspaceId, string $entryId): array;
}

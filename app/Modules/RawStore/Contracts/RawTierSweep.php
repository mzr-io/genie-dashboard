<?php

namespace App\Modules\RawStore\Contracts;

use Illuminate\Database\ConnectionInterface;

/**
 * The deletes of the raw tier (Story 2.16): the only code that deletes `raw_bodies` or `raw_observations`, run as role `maintenance` on the
 * connection and in the Workspace transaction the caller passes in. Ingestion's `SweepRawHistory` decides which rule applies and when; this
 * contract carries it out. Every method is one batch (at most {@see self::BATCH} rows per statement, the rest waits for the next run) and
 * returns how many rows it deleted. Each statement reads the target's `current_payload_id` and `payload_seq` itself, in the same snapshot as
 * its delete, so "current" is never judged from an older read. The body named by `current_payload_id` and its newest observation (the one
 * with `seq = payload_seq`) are never deleted by any method except {@see self::purgeTarget} of a retired target.
 *
 * A body whose delete meets a foreign-key violation (a concurrent `put` stored it again) is skipped until the next run.
 */
interface RawTierSweep
{
    public const BATCH = 1000;

    /**
     * `latest` targets: deletes observations older than the current one (`seq < payload_seq`) whose successor observation is older than the grace.
     */
    public function deleteSupersededObservations(ConnectionInterface $db, string $workspaceId, int $graceSeconds): int;

    /**
     * `window` targets: deletes observations older than the target's window, except the one with `seq = payload_seq`.
     */
    public function deleteObservationsOutsideWindow(ConnectionInterface $db, string $workspaceId): int;

    /**
     * Deletes bodies that are not current and have no observation, for the targets of one retention mode (`latest` or `window`).
     */
    public function deleteOrphanBodies(ConnectionInterface $db, string $workspaceId, string $mode): int;

    /**
     * A retired target past its cold time: deletes its observations, then its bodies, one batch each.
     *
     * @return array{observations: int, bodies: int, remaining: bool} `remaining` is true while any raw row of the target is left
     */
    public function purgeTarget(ConnectionInterface $db, string $workspaceId, string $syncTargetId): array;
}

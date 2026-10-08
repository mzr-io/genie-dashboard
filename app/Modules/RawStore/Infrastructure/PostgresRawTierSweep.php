<?php

namespace App\Modules\RawStore\Infrastructure;

use App\Modules\RawStore\Contracts\RawTierSweep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The deletes, one SQL statement per batch. Each joins `sync_targets` so that a target's `current_payload_id` and `payload_seq` are judged in
 * the same snapshot as the delete (the role holds no UPDATE and cannot lock a target, so the one race left, a concurrent `put` that stores a
 * body again, shows up as a foreign-key violation and the body is skipped until the next run). The `maintenance` role has SELECT and DELETE
 * only, and row-level security keeps every statement inside the Workspace context the caller set; the Workspace ID is also in each predicate.
 */
final class PostgresRawTierSweep implements RawTierSweep
{
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function deleteSupersededObservations(ConnectionInterface $db, string $workspaceId, int $graceSeconds): int
    {
        if ($graceSeconds < 1) {
            return 0;
        }

        return $db->delete(
            'with victims as ('
            .'select o.id, o.observed_at from raw_observations o '
            .'join sync_targets t on t.workspace_id = o.workspace_id and t.id = o.sync_target_id '
            ."where o.workspace_id = ? and t.retention_mode = 'latest' and o.seq < t.payload_seq "
            .'and (select s.observed_at from raw_observations s where s.workspace_id = o.workspace_id and s.sync_target_id = o.sync_target_id and s.seq > o.seq order by s.seq limit 1) '
            .'< now() - make_interval(secs => ?::double precision) '
            .'order by o.observed_at, o.id limit '.self::BATCH.') '
            .'delete from raw_observations d using victims v where d.id = v.id and d.observed_at = v.observed_at',
            [strtolower($workspaceId), $graceSeconds],
        );
    }

    public function deleteObservationsOutsideWindow(ConnectionInterface $db, string $workspaceId): int
    {
        return $db->delete(
            'with victims as ('
            .'select o.id, o.observed_at from raw_observations o '
            .'join sync_targets t on t.workspace_id = o.workspace_id and t.id = o.sync_target_id '
            ."where o.workspace_id = ? and t.retention_mode = 'window' and t.retention_days is not null "
            .'and o.seq <> t.payload_seq and o.observed_at < now() - make_interval(days => t.retention_days) '
            .'order by o.observed_at, o.id limit '.self::BATCH.') '
            .'delete from raw_observations d using victims v where d.id = v.id and d.observed_at = v.observed_at',
            [strtolower($workspaceId)],
        );
    }

    public function deleteOrphanBodies(ConnectionInterface $db, string $workspaceId, string $mode): int
    {
        if (! in_array($mode, ['latest', 'window'], true)) {
            throw new InvalidArgumentException('Unknown retention mode.');
        }

        $victims = 'select b.id from raw_bodies b '
            .'join sync_targets t on t.workspace_id = b.workspace_id and t.id = b.sync_target_id '
            .'where b.workspace_id = ? and t.retention_mode = ? and b.id is distinct from t.current_payload_id '
            .'and not exists (select 1 from raw_observations o where o.workspace_id = b.workspace_id and o.payload_id = b.id) '
            .'order by b.created_at, b.id limit '.self::BATCH;

        return $this->deleteBodies($db, $workspaceId, $victims, [strtolower($workspaceId), $mode]);
    }

    public function purgeTarget(ConnectionInterface $db, string $workspaceId, string $syncTargetId): array
    {
        if (! Str::isUuid($syncTargetId)) {
            throw new InvalidArgumentException('A raw payload belongs to a sync target.');
        }

        $workspaceId = strtolower($workspaceId);
        $syncTargetId = strtolower($syncTargetId);

        $observations = $db->delete(
            'with victims as (select id, observed_at from raw_observations where workspace_id = ? and sync_target_id = ? order by observed_at, id limit '.self::BATCH.') '
            .'delete from raw_observations d using victims v where d.id = v.id and d.observed_at = v.observed_at',
            [$workspaceId, $syncTargetId],
        );

        $bodies = $this->deleteBodies(
            $db,
            $workspaceId,
            'select b.id from raw_bodies b where b.workspace_id = ? and b.sync_target_id = ? '
            .'and not exists (select 1 from raw_observations o where o.workspace_id = b.workspace_id and o.payload_id = b.id) '
            .'order by b.created_at, b.id limit '.self::BATCH,
            [$workspaceId, $syncTargetId],
        );

        $left = $db->selectOne(
            'select (exists (select 1 from raw_observations where workspace_id = ? and sync_target_id = ?) '
            .'or exists (select 1 from raw_bodies where workspace_id = ? and sync_target_id = ?)) as remaining',
            [$workspaceId, $syncTargetId, $workspaceId, $syncTargetId],
        );

        return ['observations' => $observations, 'bodies' => $bodies, 'remaining' => filter_var($left->remaining ?? true, FILTER_VALIDATE_BOOLEAN)];
    }

    /**
     * Deletes the bodies the victim query selects in one statement; when a concurrent `put` made one of them referenced again the whole
     * statement fails on its savepoint, and the bodies are then tried one by one, skipping each that is still referenced.
     *
     * @param  list<string>  $bindings
     */
    private function deleteBodies(ConnectionInterface $db, string $workspaceId, string $victims, array $bindings): int
    {
        try {
            return $db->transaction(fn (): int => $db->delete("delete from raw_bodies d using ({$victims}) v where d.workspace_id = ? and d.id = v.id", [...$bindings, strtolower($workspaceId)]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== self::FOREIGN_KEY_VIOLATION) {
                throw $e;
            }
        }

        $deleted = 0;

        foreach ($db->select($victims, $bindings) as $row) {
            try {
                $deleted += $db->transaction(fn (): int => $db->delete('delete from raw_bodies where workspace_id = ? and id = ?', [strtolower($workspaceId), $row->id]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== self::FOREIGN_KEY_VIOLATION) {
                    throw $e;
                }
            }
        }

        return $deleted;
    }
}

<?php

namespace App\Modules\Ingestion\Application;

use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The maintenance sweep that closes the gap between a stored payload and its consumers (Story 2.20; AR-36): a sync target whose `payload_seq` is
 * ahead of the highest `subject_seq` that any consumer applied for subject `sync_target:{id}` (`outbox_consumptions`) gets its
 * `ingestion.payload.changed` event emitted again, once per run per target. Run by {@see ReemitPayloadEventsJob} on `worker-compute`; it lists the
 * Workspaces through the `maintenance` role and enters each one itself, in its own transaction, as the application role (it only reads tenant rows
 * and inserts outbox events).
 *
 * A target is left alone while an event for its subject is younger than {@see GRACE_SECONDS} (the relay and the consumers need time to catch up),
 * and one run takes at most {@see PER_WORKSPACE} targets per Workspace; the rest wait for the next run. The event carries the same IDs and sequence
 * numbers as the original, plus `reemitted`, and the latest generation of a group member. Nothing here calls a source or reads a body.
 */
final class ReemitPayloadEvents
{
    public const GRACE_SECONDS = 300;

    public const PER_WORKSPACE = 100;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
        private readonly Outbox $outbox,
        private readonly MetricEmitter $metrics,
    ) {}

    /** @return int how many events were emitted again across all Workspaces */
    public function run(): int
    {
        /** @var Connection $maintenance */
        $maintenance = $this->db->connection(SweepRawHistory::CONNECTION);
        $total = 0;

        foreach ($maintenance->select('select id from workspaces order by id') as $row) {
            $workspaceId = strtolower((string) $row->id);

            try {
                $done = $this->transactions->run($workspaceId, fn (): int => $this->reemit($workspaceId));
            } catch (Throwable $e) {
                // One Workspace failing must not stop the others; only the class is logged.
                Log::error('ingestion.reemit.workspace_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

                continue;
            }

            if ($done > 0) {
                $this->metrics->increment('dashflow.ingestion.payload_reemitted', ['workspace_id' => $workspaceId], $done);
            }

            $total += $done;
        }

        return $total;
    }

    private function reemit(string $workspaceId): int
    {
        $targets = DB::select(
            'select t.id, t.endpoint_id, t.current_payload_id, t.payload_seq, t.applied_seq, t.sync_group_id from sync_targets t where t.workspace_id = ? and t.retired_at is null and t.current_payload_id is not null '
            ."and t.payload_seq > coalesce((select max(c.subject_seq) from outbox_consumptions c where c.workspace_id = t.workspace_id and c.subject = 'sync_target:' || t.id::text and c.applied), 0) "
            ."and not exists (select 1 from outbox_events e where e.workspace_id = t.workspace_id and e.subject = 'sync_target:' || t.id::text and e.occurred_at > now() - make_interval(secs => ?)) "
            .'order by t.id limit '.self::PER_WORKSPACE,
            [$workspaceId, self::GRACE_SECONDS],
        );

        foreach ($targets as $target) {
            $id = strtolower((string) $target->id);
            $generation = DB::selectOne(
                'select id, complete, primary_ok, comparison_ok from sync_generations where workspace_id = ? and sync_group_id = ? order by dispatch_seq desc limit 1',
                [$workspaceId, strtolower((string) $target->sync_group_id)],
            );

            $this->outbox->emit(AuditAction::IngestionPayloadChanged, 'sync_target:'.$id, [
                'sync_target_id' => $id,
                'endpoint_id' => strtolower((string) $target->endpoint_id),
                'payload_id' => strtolower((string) $target->current_payload_id),
                'payload_seq' => (int) $target->payload_seq,
                'dispatch_seq' => max(1, (int) $target->applied_seq),
                'reemitted' => true,
            ] + ($generation === null ? [] : [
                'sync_group_id' => strtolower((string) $target->sync_group_id),
                'generation_id' => strtolower((string) $generation->id),
                'generation_complete' => (bool) $generation->complete,
                'failed_side' => match (true) {
                    ! $generation->primary_ok => 'primary',
                    ! $generation->comparison_ok => 'comparison',
                    default => null,
                },
            ]));
        }

        return count($targets);
    }
}

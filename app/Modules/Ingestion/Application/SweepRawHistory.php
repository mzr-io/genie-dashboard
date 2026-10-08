<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Modules\RawStore\Contracts\RawTierSweep;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The retention sweep (Story 2.16), run by {@see SweepRawHistoryJob} on `worker-compute`, the only holder of the `maintenance` database
 * role. It decides, Workspace by Workspace (each in its own transaction on the `maintenance` connection with the Workspace context set), which
 * rule applies to which target; {@see RawTierSweep} does the deletes, and nobody else deletes the raw tier. The rules read the retention copied
 * onto each target and the target's own `current_payload_id` and `payload_seq`:
 *
 *  - `latest`: observations older than the current one are deleted once their successor is older than `sync.superseded_payload_grace`, then the
 *    bodies that are not current and have no observation. With the grace unset or malformed this rule does nothing.
 *  - `window(N)`: observations older than N days are deleted except the current payload's, then the same body clean-up. No grace.
 *  - cold: a target retired for longer than `sync.cold_purge_after` loses its observations, bodies and the target row. A target that is not
 *    retired is never purged, so a current payload that is the only copy survives. Unset or malformed: nothing is purged.
 *
 * The current payload's body and its newest observation are never deleted by the first two rules. Each statement is one fixed batch; what is
 * left waits for the next run. The sweep writes no audit event (saving the setting is the audited act); metrics carry the Workspace and the kind.
 */
final class SweepRawHistory
{
    public const CONNECTION = 'maintenance';

    /** How many cold targets one run takes per Workspace. */
    public const COLD_TARGETS = 20;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
        private readonly RawTierSweep $sweep,
        private readonly SyncSettings $settings,
        private readonly MetricEmitter $metrics,
    ) {}

    /** @return array{observations: int, bodies: int, targets: int} the rows deleted across all Workspaces */
    public function run(): array
    {
        /** @var Connection $maintenance */
        $maintenance = $this->db->connection(self::CONNECTION);
        $total = ['observations' => 0, 'bodies' => 0, 'targets' => 0];

        // `workspaces` is a global table (no Workspace context); `maintenance` can read it and nothing else outside a context.
        foreach ($maintenance->select('select id from workspaces order by id') as $row) {
            $workspaceId = strtolower((string) $row->id);

            try {
                $done = $this->transactions->runIsolated(self::CONNECTION, $workspaceId, fn (Connection $db): array => $this->sweepWorkspace($db, $workspaceId));
            } catch (Throwable $e) {
                // One Workspace failing (or a lost race) must not stop the others; nothing from the error but its class is logged.
                Log::error('ingestion.sweep.workspace_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

                continue;
            }

            foreach (['observations' => 'observation', 'bodies' => 'body', 'targets' => 'target'] as $key => $kind) {
                $total[$key] += $done[$key];

                if ($done[$key] > 0) {
                    $this->metrics->increment('dashflow.ingestion.raw_swept', ['workspace_id' => $workspaceId, 'kind' => $kind], $done[$key]);
                }
            }
        }

        return $total;
    }

    /** @return array{observations: int, bodies: int, targets: int} */
    private function sweepWorkspace(Connection $db, string $workspaceId): array
    {
        $observations = 0;
        $bodies = 0;
        $targets = 0;

        $grace = $this->settings->supersededGraceSeconds();

        if ($grace !== null) {
            $observations += $this->sweep->deleteSupersededObservations($db, $workspaceId, $grace);
            $bodies += $this->sweep->deleteOrphanBodies($db, $workspaceId, 'latest');
        }

        $observations += $this->sweep->deleteObservationsOutsideWindow($db, $workspaceId);
        $bodies += $this->sweep->deleteOrphanBodies($db, $workspaceId, 'window');

        $cold = $this->settings->coldPurgeAfterSeconds();

        if ($cold !== null) {
            $due = $db->select(
                'select id from sync_targets where workspace_id = ? and retired_at is not null and retired_at < now() - make_interval(secs => ?::double precision) order by retired_at, id limit '.self::COLD_TARGETS,
                [$workspaceId, $cold],
            );

            foreach ($due as $target) {
                $purged = $this->sweep->purgeTarget($db, $workspaceId, (string) $target->id);
                $observations += $purged['observations'];
                $bodies += $purged['bodies'];

                // The target row goes only when none of its raw rows is left: the rest of a big target is taken by the next run.
                if (! $purged['remaining']) {
                    $targets += $db->delete('delete from sync_targets where workspace_id = ? and id = ? and retired_at is not null', [$workspaceId, (string) $target->id]);
                }
            }
        }

        return ['observations' => $observations, 'bodies' => $bodies, 'targets' => $targets];
    }
}

<?php

namespace App\Modules\Results\Application;

use App\Modules\Blocks\Contracts\PublishedConfigs;
use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Results\Contracts\ResultKey;
use App\Modules\Results\Contracts\ResultStore;
use App\Platform\Tenancy\WorkspaceSettings;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The maintenance sweep that makes results converge without anyone acting (Story 4.2; AR-36). For every watched sync target it compares the target's
 * `payload_seq` with the `payload_seq` of the result stored for each of its subscriptions; a target with a missing or older result (a lost event, a
 * flushed or expired cache, a failed job) gets one {@see ComputeResultsJob}. It lists Workspaces through the `maintenance` role and enters each one
 * itself, in its own transaction, and takes at most {@see PER_WORKSPACE} targets per Workspace per run, resuming after the last one next time.
 */
final class SweepStaleResults
{
    public const PER_WORKSPACE = 200;

    public const CONNECTION = 'maintenance';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
        private readonly ComputeSources $sources,
        private readonly PublishedConfigs $configs,
        private readonly ResultStore $store,
        private readonly WorkspaceSettings $settings,
    ) {}

    /** @return int how many compute jobs were queued across all Workspaces */
    public function run(): int
    {
        $total = 0;

        foreach ($this->db->connection(self::CONNECTION)->select('select id from workspaces order by id') as $row) {
            $workspaceId = strtolower((string) $row->id);

            try {
                $total += $this->transactions->run($workspaceId, fn (): int => $this->sweep($workspaceId));
            } catch (Throwable $e) {
                // One Workspace failing must not stop the others; only the class is logged.
                Log::error('results.sweep.workspace_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            }
        }

        return $total;
    }

    private function sweep(string $workspaceId): int
    {
        $queued = 0;

        foreach ($this->sources->watched($workspaceId, self::PER_WORKSPACE) as $target) {
            foreach ($this->sources->subscriptions($workspaceId, $target->id) as $subscription) {
                $config = $this->configs->find($workspaceId, $subscription->blockVersionId);

                // A version that is not published has no result to keep up to date.
                if ($config === null || $config->state !== 'published') {
                    continue;
                }

                $context = ResultContexts::of($subscription, $config->contractVersion, $this->settings);
                $stored = $this->store->payloadSeq($workspaceId, ResultKey::of($workspaceId, $subscription->blockVersionId, $target->fetchKey, $context));

                if ($stored === null || $stored < $target->payloadSeq) {
                    ComputeResultsJob::dispatch($workspaceId, $target->id)->afterCommit();
                    $queued++;

                    break;
                }
            }
        }

        return $queued;
    }
}

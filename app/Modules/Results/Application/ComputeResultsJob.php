<?php

namespace App\Modules\Results\Application;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Computes the Block Results of one sync target (Story 4.2), on `compute` (so on `worker-compute`). A signed, Workspace-scoped job that carries IDs only:
 * the Workspace is re-entered and the target's ID is re-read under row-level security before anything runs. The payload sequence is not carried: a job
 * always computes from the target's current payload, so a late or repeated job is harmless, and the compare-and-set keeps the newer result.
 */
final class ComputeResultsJob implements ShouldQueue, WorkspaceScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace;

    public const QUEUE = 'compute';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $workspaceId, public readonly string $syncTargetId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function referencedIds(): array
    {
        return ['sync_targets' => [$this->syncTargetId]];
    }

    /** @return list<int> seconds between tries */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(ComputeResults $compute): void
    {
        $compute->forTarget($this->workspaceId, $this->syncTargetId);
    }
}

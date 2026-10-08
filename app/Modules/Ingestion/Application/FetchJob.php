<?php

namespace App\Modules\Ingestion\Application;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One scheduled fetch of one sync group (Story 2.14), on `worker-connector` queue `fetch-scheduled`. A signed, Workspace-scoped job with IDs and
 * the dispatch number only: the Workspace is re-entered and the group ID is re-read under row-level security before anything runs, and an ID that
 * is not visible is a hard failure with a security event, with no request made. One try: a lost run is dispatched again at the next due time.
 */
final class FetchJob implements ShouldQueue, WorkspaceScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace;

    public const QUEUE = 'fetch-scheduled';

    public int $tries = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $syncGroupId,
        public readonly int $dispatchSeq,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function referencedIds(): array
    {
        return ['sync_targets' => [$this->syncGroupId]];
    }

    public function handle(FetchSyncTarget $fetch): void
    {
        $fetch->run($this->workspaceId, $this->syncGroupId, $this->dispatchSeq);
    }
}

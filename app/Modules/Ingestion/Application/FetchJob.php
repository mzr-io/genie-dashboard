<?php

namespace App\Modules\Ingestion\Application;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Story 2.17: the call number within this dispatch (1 for the first); a retry is this job queued again with `attempt` + 1. Not `readonly`
     * and not promoted: a payload queued before this story has no such property, and unserializing it must leave the default 1.
     */
    public int $attempt = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $syncGroupId,
        public readonly int $dispatchSeq,
        int $attempt = 1,
    ) {
        $this->attempt = $attempt;
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
        $fetch->run($this->workspaceId, $this->syncGroupId, $this->dispatchSeq, $this->attempt);
    }

    /**
     * A job that threw or whose worker was killed (Story 2.17): under the dispatch fence, a `failed` run with `job-failed` and the request ID, and
     * one counted failure. It re-enters the Workspace itself (the job's own transaction is gone) and never throws: only the exception class is logged.
     */
    public function failed(?Throwable $exception = null): void
    {
        try {
            app(WorkspaceTransaction::class)->run($this->workspaceId, fn () => app(FetchSyncTarget::class)->jobFailed(
                $this->workspaceId, $this->syncGroupId, $this->dispatchSeq, $this->attempt,
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.job_failure_not_recorded', ['workspace_id' => $this->workspaceId, 'exception' => $e::class]);
        }
    }
}

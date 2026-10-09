<?php

namespace App\Modules\Ingestion\Application;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One health probe of one Data Source (Story 2.18), on `worker-connector` queue `fetch-scheduled`. A signed, Workspace-scoped job with IDs
 * only: the Workspace is re-entered and the Data Source ID is re-read under row-level security before anything runs, and an ID that is not
 * visible is a hard failure with a security event, with no request made. One try: a lost probe is made again by the next save or periodic tick.
 * `periodic` marks a probe the due-probe tick asked for: it is skipped when the source has current sync targets, since their fetches already prove it.
 */
final class ProbeDataSourceJob implements ShouldQueue, WorkspaceScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace;

    public const QUEUE = 'fetch-scheduled';

    public int $tries = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $dataSourceId,
        public readonly bool $periodic = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function referencedIds(): array
    {
        return ['data_sources' => [$this->dataSourceId]];
    }

    public function handle(RecordProbeResult $probe): void
    {
        $probe->run($this->workspaceId, $this->dataSourceId, $this->periodic);
    }
}

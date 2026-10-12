<?php

namespace App\Modules\Blocks\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes the rows of discarded Drafts (Story 3.3). Queued on `maintenance`, so it runs on `worker-compute`, the only service holding the
 * `maintenance` database credentials; the web tier never has them and `app` has no DELETE. Like every queued payload it is signed, and the
 * Workspace ID it carries is part of what is signed. A Workspace ID restricts the run to that Workspace (the discard asks for it after its
 * commit); none means every Workspace (the scheduler's safety net). Each Workspace is entered in its own transaction under row-level security.
 */
final class PurgeDiscardedDraftsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public readonly ?string $workspaceId = null)
    {
        $this->onQueue('maintenance');
    }

    public function handle(PurgeDiscardedDrafts $purge): void
    {
        $purge->run($this->workspaceId);
    }
}

<?php

namespace App\Modules\Blocks\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Activates versions left `prepared` for longer than `precompute_timeout` (Story 3.19). Queued on `maintenance` like {@see PurgeDiscardedDraftsJob}, so it
 * runs on `worker-compute`, which holds the `maintenance` credentials the candidate query uses; the activation itself is {@see ActivateVersion}.
 */
final class ActivatePreparedVersionsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public readonly ?string $workspaceId = null)
    {
        $this->onQueue('maintenance');
    }

    public function handle(ActivatePreparedVersions $activate): void
    {
        $activate->run($this->workspaceId);
    }
}

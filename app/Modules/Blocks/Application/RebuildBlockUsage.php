<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Makes `block_usage` again from the dashboards' change events (Story 6.2): the read model is a function of the events, so a Workspace's can be cleared
 * and replayed in subject order and reach the same counts. The events come from the caller: the outbox table is the kernel's, not Blocks'.
 */
final class RebuildBlockUsage
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    /**
     * Clears the Workspace's model and applies the events given, which the caller (the outbox kernel's maintenance) has read in subject order.
     *
     * @param  iterable<OutboxEnvelope>  $events  `dashboards.dashboard.changed` events of the Workspace, oldest first within each dashboard
     * @return int the events applied
     */
    public function replay(string $workspaceId, iterable $events): int
    {
        return $this->transactions->run($workspaceId, function () use ($events): int {
            DB::select('select blocks_reset_block_usage()');
            $applied = 0;

            foreach ($events as $event) {
                if ((new TrackBlockUsage)->handles($event) && TrackBlockUsage::apply($event)) {
                    $applied++;
                }
            }

            return $applied;
        });
    }
}

<?php

namespace App\Modules\Results\Application;

use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Results' read model of who has which Block on a dashboard (Story 4.13), fed by `dashboards.dashboard.changed` (IDs only). An add or a restore counts
 * one more item of the member's for the Block; a remove counts one fewer and the row goes when none is left. A fan-out can then find its recipients
 * without reading a Dashboards table. Idempotent per event (the kernel records `(consumer, event_id)`), and in order per dashboard.
 */
final class TrackBlockViewers implements OutboxConsumer
{
    public const NAME = 'results.track_block_viewers';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return $event->type === 'dashboards.dashboard.changed'
            && in_array($event->data['command'] ?? null, ['add_item', 'restore_item', 'remove_item'], true);
    }

    public function handle(OutboxEnvelope $event): void
    {
        $block = $event->data['block_id'] ?? null;
        $member = $event->data['membership_id'] ?? null;

        if (! is_string($block) || ! is_string($member) || ! Str::isUuid($block) || ! Str::isUuid($member)) {
            return;
        }

        if ($event->data['command'] === 'remove_item') {
            DB::select('select results_release_block_viewer(?::uuid, ?::uuid)', [strtolower($member), strtolower($block)]);

            return;
        }

        DB::insert(
            'insert into block_viewers (workspace_id, membership_id, block_id, items, created_at, updated_at) values (?, ?, ?, 1, now(), now()) '
            .'on conflict (workspace_id, block_id, membership_id) do update set items = block_viewers.items + 1, updated_at = now()',
            [$event->workspaceId, strtolower($member), strtolower($block)],
        );
    }
}

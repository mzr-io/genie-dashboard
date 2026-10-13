<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blocks' read model of who has which Block on a dashboard (Story 6.2), fed by `dashboards.dashboard.changed` (IDs only). An add or a restore counts one
 * more item of the member's for the Block, a remove one fewer, and the row goes at zero, so a member is counted once however many dashboards hold the
 * Block. Idempotent per event (the kernel records `(consumer, event_id)`), and an event at or below the last `subject_seq` applied for its dashboard is
 * dropped, so a replay or a late one changes nothing.
 */
final class TrackBlockUsage implements OutboxConsumer
{
    public const NAME = 'blocks.track_block_usage';

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
        self::apply($event);
    }

    /** Applies one event; false when it was dropped (older, a replay or not about a Block and a member). */
    public static function apply(OutboxEnvelope $event): bool
    {
        $block = $event->data['block_id'] ?? null;
        $member = $event->data['membership_id'] ?? null;

        if (! is_string($block) || ! is_string($member) || ! Str::isUuid($block) || ! Str::isUuid($member)) {
            return false;
        }

        // The cursor moves only forward: no row comes back when the event is not newer than what was applied.
        $moved = DB::selectOne(
            'insert into block_usage_cursors (workspace_id, subject, last_seq, updated_at) values (?, ?, ?, now()) '
            .'on conflict (workspace_id, subject) do update set last_seq = excluded.last_seq, updated_at = now() where block_usage_cursors.last_seq < excluded.last_seq '
            .'returning last_seq',
            [$event->workspaceId, $event->subject, $event->subjectSeq],
        );

        if ($moved === null) {
            return false;
        }

        if (($event->data['command'] ?? null) === 'remove_item') {
            DB::select('select blocks_release_block_usage(?::uuid, ?::uuid)', [strtolower($block), strtolower($member)]);

            return true;
        }

        DB::insert(
            "insert into block_usage (workspace_id, block_id, membership_id, kind, items, created_at, updated_at) values (?, ?, ?, 'user', 1, now(), now()) "
            .'on conflict (workspace_id, block_id, kind, membership_id) do update set items = block_usage.items + 1, updated_at = now()',
            [$event->workspaceId, strtolower($block), strtolower($member)],
        );

        return true;
    }
}

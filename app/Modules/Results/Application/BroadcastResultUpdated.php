<?php

namespace App\Modules\Results\Application;

use App\Modules\Results\Contracts\ResultUpdated;
use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relays `results.result.updated` to the realtime channels (Story 4.13). Shared data goes to the Block's channel, a member's own data to that
 * member's channel, and only when somebody is viewing it (`block_viewers`), so an unwatched Block costs nothing. A broadcast that cannot be sent
 * (Reverb or the queue store down) is swallowed here: clients fall back to polling, and the result itself is already stored.
 */
final class BroadcastResultUpdated implements OutboxConsumer
{
    public const NAME = 'results.broadcast_result_updated';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return $event->type === 'results.result.updated';
    }

    public function handle(OutboxEnvelope $event): void
    {
        $block = $event->data['block_id'] ?? null;
        $etag = $event->data['result_etag'] ?? null;

        if (! is_string($block) || ! Str::isUuid($block) || ! is_string($etag)) {
            return;
        }

        $member = ($event->data['scope'] ?? 'shared') === 'member' && is_string($event->data['membership_id'] ?? null)
            ? strtolower((string) $event->data['membership_id'])
            : null;

        $viewing = DB::selectOne(
            'select 1 as viewing from block_viewers where workspace_id = ? and block_id = ? and (?::uuid is null or membership_id = ?::uuid) limit 1',
            [$event->workspaceId, strtolower($block), $member, $member],
        );

        if ($viewing === null) {
            return;
        }

        try {
            ResultUpdated::dispatch($event->workspaceId, $block, $etag, $member);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

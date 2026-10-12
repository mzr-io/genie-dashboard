<?php

namespace App\Modules\Results\Application;

use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Str;

/**
 * Results' consumer of `ingestion.payload.changed` (Story 4.2). It queues one {@see ComputeResultsJob} for the event's sync target and does nothing
 * else: no payload is read and no result is built inside the relay's transaction. A redelivery is ignored by the kernel; a lost event is closed by
 * {@see SweepStaleResults}.
 */
final class ComputeOnPayloadChanged implements OutboxConsumer
{
    public const NAME = 'results.compute_on_payload_changed';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return $event->type === 'ingestion.payload.changed';
    }

    public function handle(OutboxEnvelope $event): void
    {
        $targetId = $event->data['sync_target_id'] ?? null;

        if (! is_string($targetId) || ! Str::isUuid($targetId)) {
            return;
        }

        ComputeResultsJob::dispatch($event->workspaceId, strtolower($targetId))->afterCommit();
    }
}

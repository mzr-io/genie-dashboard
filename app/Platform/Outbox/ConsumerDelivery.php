<?php

namespace App\Platform\Outbox;

use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;

/**
 * Delivers one event to one consumer, exactly-once in effect. Inside the event's Workspace transaction it
 * records `(consumer, event_id)` in `outbox_consumptions` (a redelivery hits the unique key and is
 * ignored) and drops an event whose `subject_seq` is not newer than the consumer's last applied one for
 * that subject. The consumption row and the consumer's own writes commit together, so a failing
 * consumer leaves no record and the event is delivered again.
 */
final class ConsumerDelivery
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
    ) {}

    public function deliver(OutboxConsumer $consumer, OutboxEnvelope $event): ConsumptionResult
    {
        if (! $consumer->handles($event)) {
            return ConsumptionResult::Skipped;
        }

        return $this->transactions->run($event->workspaceId, function () use ($consumer, $event): ConsumptionResult {
            $connection = $this->db->connection();
            $table = fn () => $connection->table('outbox_consumptions');

            // One delivery at a time per (consumer, Workspace, subject): the stale check and the insert are atomic.
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['consumption|'.$consumer->name().'|'.$event->workspaceId.'|'.$event->subject]);

            $lastApplied = $table()
                ->where('consumer', $consumer->name())
                ->where('subject', $event->subject)
                ->where('applied', true)
                ->max('subject_seq');

            $stale = $lastApplied !== null && $event->subjectSeq <= (int) $lastApplied;

            // The unique key (consumer, event_id) makes a concurrent or repeated delivery wait, then lose.
            $inserted = $table()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'workspace_id' => $event->workspaceId,
                'consumer' => $consumer->name(),
                'event_id' => $event->eventId,
                'subject' => $event->subject,
                'subject_seq' => $event->subjectSeq,
                'applied' => ! $stale,
                'consumed_at' => now(),
            ]);

            if ($inserted === 0) {
                return ConsumptionResult::Duplicate;
            }

            if ($stale) {
                return ConsumptionResult::Stale;
            }

            $consumer->handle($event);

            return ConsumptionResult::Applied;
        });
    }
}

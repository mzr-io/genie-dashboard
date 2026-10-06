<?php

namespace App\Platform\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The outbox relay. Runs as database role `system` on the `system` connection: it selects pending events
 * `FOR UPDATE SKIP LOCKED` (two relay workers never take the same row), delivers each once to every
 * registered consumer inside that event's own Workspace context (on the `app` connection), and sets
 * `sent_at`. A failed delivery leaves the event pending, and so do the later events of the same subject
 * in the batch, so a subject's events are never delivered out of order. Consumers dedupe on
 * `(consumer, event_id)`, so a crash between delivery and `sent_at` only repeats a harmless redelivery.
 */
final class OutboxRelay
{
    public const CONNECTION = 'system';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly OutboxConsumers $consumers,
        private readonly ConsumerDelivery $delivery,
    ) {}

    /**
     * Relays up to `$limit` pending events and returns how many were marked sent.
     */
    public function relay(int $limit = 100): int
    {
        // Nothing can take an event until a consumer is registered: leave everything pending.
        if ($this->consumers->all() === []) {
            return 0;
        }

        /** @var Connection $system */
        $system = $this->db->connection(self::CONNECTION);

        return $system->transaction(function () use ($system, $limit): int {
            $sent = 0;
            $blocked = [];

            // Marking an event sent makes its subject's next event eligible, so keep passing while events are
            // being sent: a subject with a backlog drains in one run, still strictly in order.
            do {
                $rows = $system->table('outbox_events')
                    ->whereNull('sent_at')
                    // Only a subject's oldest unsent event: SKIP LOCKED is per row, and the subquery still sees a
                    // row another worker holds locked, so a later event never overtakes an earlier one.
                    ->whereNotExists(function ($earlier): void {
                        $earlier->selectRaw('1')->from('outbox_events as earlier')
                            ->whereColumn('earlier.workspace_id', 'outbox_events.workspace_id')
                            ->whereColumn('earlier.subject', 'outbox_events.subject')
                            ->whereColumn('earlier.subject_seq', '<', 'outbox_events.subject_seq')
                            ->whereNull('earlier.sent_at');
                    })
                    ->orderBy('occurred_at')
                    ->orderBy('subject_seq')
                    ->limit($limit)
                    ->lock('for update skip locked')
                    ->get(['id', 'workspace_id', 'type', 'v', 'subject', 'subject_seq', 'occurred_at', 'actor', 'request_id', 'data']);

                $sentInPass = 0;

                foreach ($rows as $row) {
                    $key = $row->workspace_id.'|'.$row->subject;

                    if (isset($blocked[$key])) {
                        continue;
                    }

                    try {
                        $event = OutboxEnvelope::fromRow((array) $row);

                        foreach ($this->consumers->all() as $consumer) {
                            $this->delivery->deliver($consumer, $event);
                        }
                    } catch (Throwable $e) {
                        $blocked[$key] = true;
                        // IDs and the failure reason only: never the event data.
                        Log::error('outbox.delivery.failed', ['event_id' => $row->id, 'workspace_id' => $row->workspace_id, 'type' => $row->type, 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);

                        continue;
                    }

                    $system->table('outbox_events')->where('id', $row->id)->update(['sent_at' => now()]);
                    $sentInPass++;
                }

                $sent += $sentInPass;
            } while ($sentInPass > 0 && $sent < $limit);

            return $sent;
        });
    }
}

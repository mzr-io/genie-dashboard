<?php

namespace App\Platform\Outbox;

/**
 * A module's subscriber to outbox events. The kernel calls no module, so a module registers its consumers
 * with OutboxConsumers. The kernel runs `handle` inside the event's Workspace transaction, records
 * `(consumer, event_id)` first, ignores a redelivery and drops an event that is not newer than the
 * subject's last applied `subject_seq`: `handle` sees each event at most once and in order per subject.
 */
interface OutboxConsumer
{
    /** A stable, unique name (the dedupe key): `{module}.{purpose}`. */
    public function name(): string;

    public function handles(OutboxEnvelope $event): bool;

    /** Applies the event. Runs in the event's Workspace transaction; an exception leaves the event pending. */
    public function handle(OutboxEnvelope $event): void;
}

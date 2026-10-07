<?php

namespace App\Platform\Outbox;

enum ConsumptionResult: string
{
    /** The consumer handled the event. */
    case Applied = 'applied';

    /** The consumer had already recorded this event ID: the redelivery was ignored. */
    case Duplicate = 'duplicate';

    /** The event is not newer than the last one applied for its subject: dropped. */
    case Stale = 'stale';

    /** The consumer does not handle this event type. */
    case Skipped = 'skipped';
}

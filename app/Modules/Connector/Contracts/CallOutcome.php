<?php

namespace App\Modules\Connector\Contracts;

/** What one call to a Data Source tells the breaker (Story 2.17). */
enum CallOutcome: string
{
    /** The source responded (a 2xx or 304, or an answer that is the Admin's or the data's to fix): consecutive failures reset. */
    case Responded = 'ok';

    /** A transient or ambiguous failure: one more consecutive failure. */
    case Failed = 'fail';

    /** The source asked for slower calls: it neither counts nor resets. */
    case Throttled = 'throttled';
}

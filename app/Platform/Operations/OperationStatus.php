<?php

namespace App\Platform\Operations;

/** Where an Operation stands. `stale` is for a result whose subject changed afterwards (a later story sets it). */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Stale = 'stale';
    case Expired = 'expired';

    /** An Operation that has ended is not run again. */
    public function ended(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }
}

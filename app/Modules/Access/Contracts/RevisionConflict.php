<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The editor's revision is stale (`access.revision_conflict`, HTTP 409); carries the member's current state. */
final class RevisionConflict extends RuntimeException
{
    public function __construct(public readonly AccessChange $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

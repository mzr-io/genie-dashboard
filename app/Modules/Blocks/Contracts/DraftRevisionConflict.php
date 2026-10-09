<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** The caller's revision is stale (`blocks.revision_conflict`, HTTP 409); carries the current state. Nothing was written. */
final class DraftRevisionConflict extends RuntimeException
{
    public function __construct(public readonly Draft $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

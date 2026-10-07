<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The caller's revision is stale (`connector.revision_conflict`, HTTP 409); carries the current list. */
final class AllowlistRevisionConflict extends RuntimeException
{
    public function __construct(public readonly AllowlistPage $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The editor's revision of the key is stale (`access.revision_conflict`, HTTP 409); carries the key as it stands. */
final class AttributeKeyRevisionConflict extends RuntimeException
{
    public function __construct(public readonly AttributeKeyRow $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

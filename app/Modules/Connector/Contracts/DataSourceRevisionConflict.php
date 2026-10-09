<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The caller's revision is stale (`connector.revision_conflict`, HTTP 409); carries the current state. */
final class DataSourceRevisionConflict extends RuntimeException
{
    public function __construct(public readonly DataSource $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

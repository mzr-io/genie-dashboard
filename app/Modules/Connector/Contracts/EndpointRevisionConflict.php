<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The caller's revision of the Endpoint is stale (`connector.revision_conflict`, HTTP 409); carries the current state. */
final class EndpointRevisionConflict extends RuntimeException
{
    public function __construct(public readonly Endpoint $current)
    {
        parent::__construct(ErrorCode::RevisionConflict->value);
    }
}

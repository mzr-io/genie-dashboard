<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A host allowlist value that is not acceptable: HTTP 422 with a field error. */
final class InvalidHost extends RuntimeException
{
    public function __construct(public readonly HostProblem $problem)
    {
        parent::__construct('Invalid host allowlist value: '.$problem->value);
    }
}

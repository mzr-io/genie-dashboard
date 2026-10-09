<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A value that may not become a path segment (Story 2.9): empty, `.`, `..`, or holding `/`. Names the parameter, never the value. */
final class InvalidPathValue extends RuntimeException
{
    public function __construct(public readonly string $parameter)
    {
        parent::__construct("The value of the path parameter {$parameter} cannot be empty, '.', '..' or contain '/'.");
    }
}

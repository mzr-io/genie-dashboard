<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** An Endpoint path template that is not acceptable (Story 2.9). The reason is the value a client maps to its message. */
final class InvalidEndpointPath extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

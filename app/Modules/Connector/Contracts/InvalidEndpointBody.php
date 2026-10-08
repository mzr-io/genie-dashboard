<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A body template that is not acceptable (Story 2.9). The reason is the value a client maps to its message. */
final class InvalidEndpointBody extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?string $parameter = null)
    {
        parent::__construct($message);
    }
}

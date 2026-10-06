<?php

namespace App\Platform\Tenancy;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Rolls the request transaction back while still returning the response that was built. */
final class RollbackResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Request transaction rolled back.');
    }
}

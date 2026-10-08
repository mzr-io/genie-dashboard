<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A person or a Workspace has started too many Endpoint tests in the window. Nothing was enqueued; try again in `retryAfter` seconds. */
final class SampleFetchThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many endpoint tests.');
    }
}

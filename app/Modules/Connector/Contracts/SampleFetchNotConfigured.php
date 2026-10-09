<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A setting a Draft's Fetch needs is not set (Story 3.9): the environment variable that is missing. Nothing was counted or enqueued. */
final class SampleFetchNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $setting)
    {
        parent::__construct("The setting {$setting} is not set.");
    }
}

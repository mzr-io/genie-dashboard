<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A Sample Response cannot be sealed, stored or opened: no usable `data` key, or the cache refused it. The sample is not kept (it fails closed). */
final class SampleBlobUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The sample cannot be kept ({$reason}).");
    }
}

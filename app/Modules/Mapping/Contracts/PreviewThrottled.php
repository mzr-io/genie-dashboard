<?php

namespace App\Modules\Mapping\Contracts;

use RuntimeException;

/** A person has requested too many previews in the window; try again in `retryAfter` seconds. */
final class PreviewThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many previews.');
    }
}

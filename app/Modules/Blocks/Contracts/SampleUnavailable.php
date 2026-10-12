<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** The sample could not be sealed or opened (no usable `data` key, or a stored value that does not open): `blocks.sample_unavailable`, HTTP 503. Nothing is stored. */
final class SampleUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::SampleUnavailable->value);
    }
}

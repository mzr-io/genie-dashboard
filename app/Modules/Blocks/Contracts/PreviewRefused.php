<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A preview that has nothing to run: the Draft has no sample, or no Query Plan. `reason` is the Mapping error code. */
class PreviewRefused extends RuntimeException
{
    public const NO_SAMPLE = 'mapping.no_sample';

    public const NO_PLAN = 'mapping.no_plan';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The draft cannot be previewed.');
    }
}

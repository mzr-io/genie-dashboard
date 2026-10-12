<?php

namespace App\Modules\Mapping\Contracts;

use RuntimeException;

/** A run passed a guard and was aborted: there is no partial result. `guard` names which one; nothing of the sample is in it. */
final class GuardExceeded extends RuntimeException
{
    public const EXTRACTED_VALUES = 'max_extracted_values';

    public const EVALUATIONS = 'max_evaluations';

    public const WALL_TIME = 'max_wall_ms';

    public function __construct(public readonly string $guard)
    {
        parent::__construct("The run passed the {$guard} guard.");
    }
}

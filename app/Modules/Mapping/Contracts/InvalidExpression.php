<?php

namespace App\Modules\Mapping\Contracts;

use RuntimeException;

/** An expression the grammar refuses (Story 5.3, AD-12): `position` is the 1-based character where the problem starts. Nothing of any sample is in it. */
final class InvalidExpression extends RuntimeException
{
    public function __construct(string $message, public readonly int $position)
    {
        parent::__construct($message);
    }
}

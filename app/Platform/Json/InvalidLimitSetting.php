<?php

namespace App\Platform\Json;

use RuntimeException;

/** A size or depth setting is configured but is not a positive whole number. Names the setting, never its value. */
final class InvalidLimitSetting extends RuntimeException
{
    public function __construct(public readonly string $setting)
    {
        parent::__construct("The setting {$setting} is configured but is not a positive whole number.");
    }
}

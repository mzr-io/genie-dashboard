<?php

namespace App\Modules\Results\Contracts;

use RuntimeException;

/** A `pending_input` setting Results needs has no value (Story 4.2): nothing is computed or stored until the deployment sets it. */
final class ResultSettingNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $setting)
    {
        parent::__construct("The setting {$setting} is not configured.");
    }
}

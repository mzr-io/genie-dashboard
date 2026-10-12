<?php

namespace App\Modules\Mapping\Contracts;

use RuntimeException;

/** A `pending_input` preview setting is unset, or is not a positive whole number: nothing is run, and no number is invented. */
final class SettingNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $setting)
    {
        parent::__construct("{$setting} is not configured.");
    }
}

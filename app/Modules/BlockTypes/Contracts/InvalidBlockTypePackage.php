<?php

namespace App\Modules\BlockTypes\Contracts;

use RuntimeException;

/** Boot failure: the message names the package (and the slot when it is a slot's fault). */
final class InvalidBlockTypePackage extends RuntimeException
{
    public static function in(string $package, string $problem, ?string $slot = null): self
    {
        return new self("Invalid block type package {$package}".($slot !== null ? ", slot {$slot}" : '').": {$problem}");
    }
}

<?php

namespace App\Modules\BlockTypes\Contracts;

use RuntimeException;

final class UnknownBlockType extends RuntimeException
{
    public static function pair(string $key, int $contractVersion): self
    {
        return new self("Block type {$key} v{$contractVersion} is not registered.");
    }
}

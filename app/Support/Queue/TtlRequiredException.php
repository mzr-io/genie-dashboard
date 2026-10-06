<?php

namespace App\Support\Queue;

use LogicException;

/** A write to the LRU cache store carried no TTL. */
final class TtlRequiredException extends LogicException
{
    public static function forKey(string $key): self
    {
        return new self("Cache writes need a positive TTL; refused to write [{$key}] without one.");
    }
}

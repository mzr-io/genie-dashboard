<?php

namespace App\Modules\Connector\Contracts;

/** The whitelist of columns the allowlist is sorted by. */
enum AllowlistSort: string
{
    case Host = 'host';
    case Scheme = 'scheme';
    case Port = 'port';
    case Added = 'added';

    /** The sort for a client-supplied value; anything outside the whitelist falls back to the host. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Host) : self::Host;
    }
}

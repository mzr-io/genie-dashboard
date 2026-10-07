<?php

namespace App\Modules\Connector\Contracts;

/** The whitelist of columns the Data source list is sorted by. */
enum DataSourceSort: string
{
    case Name = 'name';
    case Host = 'host';
    case AuthType = 'auth_type';

    /** The sort for a client-supplied value; anything outside the whitelist falls back to the name. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Name) : self::Name;
    }
}

<?php

namespace App\Modules\Access\Contracts;

/** The columns the user list sorts by: a whitelist, never a client-supplied column name. */
enum MemberSort: string
{
    case Name = 'name';
    case Email = 'email';
    case Role = 'role';
    case Status = 'status';
    case LastActive = 'last_active';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Name) : self::Name;
    }
}

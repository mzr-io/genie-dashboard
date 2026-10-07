<?php

namespace App\Modules\Access\Contracts;

/** The whitelist of columns the group list sorts by. */
enum GroupSort: string
{
    case Name = 'name';
    case Members = 'members';
    case Created = 'created';

    /** The sort for a client-supplied value; anything outside the whitelist falls back to the name. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Name) : self::Name;
    }
}

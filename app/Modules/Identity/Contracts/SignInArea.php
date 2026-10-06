<?php

namespace App\Modules\Identity\Contracts;

/** The area a person signs in to: the personal workspace (User) or system management (Admin). Stored in the session as `area`. */
enum SignInArea: string
{
    case User = 'user';
    case Admin = 'admin';

    /** The area for the submitted role card; anything but `admin` is the User area. */
    public static function fromInput(mixed $value): self
    {
        return $value === self::Admin->value ? self::Admin : self::User;
    }
}

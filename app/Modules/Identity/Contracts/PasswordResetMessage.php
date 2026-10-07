<?php

namespace App\Modules\Identity\Contracts;

/**
 * The catalogue keys (`resources/js/locales/en.ts`) the password-reset endpoints answer with. The server
 * sends the key, never the wording: the pages look the copy up in the message catalogue.
 */
enum PasswordResetMessage: string
{
    case Requested = 'reset-requested';
    case Expired = 'reset-expired';
    case Changed = 'password-changed';
    case FieldError = 'field-error';
    case Throttled = 'throttled';
}

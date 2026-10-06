<?php

namespace App\Modules\Identity\Contracts;

/**
 * The catalogue keys (`resources/js/locales/en.ts`) the sign-in endpoint answers with. The server sends
 * the key, never the wording: the page looks the copy up in the message catalogue.
 */
enum SignInMessage: string
{
    case Failed = 'signin-failed';
    case RoleDenied = 'signin-role-denied';
    case Throttled = 'throttled';
}

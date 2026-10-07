<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SignInMessage;
use RuntimeException;

/** A sign-in that did not succeed. Carries only the catalogue message the visitor sees, never the reason. */
final class SignInRefused extends RuntimeException
{
    public function __construct(public readonly SignInMessage $reply)
    {
        parent::__construct($reply->value);
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A change that needs the Admin's password was not confirmed (HTTP 422 on `confirm_password`); nothing was written. */
final class ConfirmationRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The password was not confirmed.');
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** This process cannot open `cred` secrets: the private key file is not mounted or is not a key (always the case on `web`). */
final class KeyringUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The credential keyring is not available in this process.');
    }
}

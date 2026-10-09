<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A sealed value could not be opened for the context asked: damaged, or sealed for another Workspace, Data Source, slot or purpose. */
final class SecretRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The secret cannot be opened for this context.');
    }
}

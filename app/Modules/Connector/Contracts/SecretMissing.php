<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A secret reference points to no usable row: it never existed in this Workspace, or it was a transient row that has expired. */
final class SecretMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The secret is not available.');
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** Data Source fields that are not acceptable: HTTP 422 with field errors; nothing is written. */
final class InvalidDataSource extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages
     * @param  array<string, string>  $reasons  field => the reason a client maps to its message
     */
    public function __construct(public readonly array $errors, public readonly array $reasons)
    {
        parent::__construct('Invalid data source.');
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A Data Source Base URL that is not acceptable: HTTP 422 on `base_url`. */
final class InvalidDataSourceUrl extends RuntimeException
{
    public function __construct(public readonly UrlProblem $problem, public readonly ?HostProblem $host = null)
    {
        parent::__construct('Invalid data source base URL: '.$problem->value);
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

final class EgressGrantRefused extends RuntimeException
{
    public function __construct(public readonly CidrProblem $problem)
    {
        parent::__construct($problem->message());
    }
}

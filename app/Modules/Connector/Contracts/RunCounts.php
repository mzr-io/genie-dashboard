<?php

namespace App\Modules\Connector\Contracts;

/** How many final scheduled-fetch runs of a Data Source succeeded and failed (Story 2.18). A 304 or an unchanged body is a success. */
final readonly class RunCounts
{
    public function __construct(public int $succeeded = 0, public int $failed = 0) {}
}

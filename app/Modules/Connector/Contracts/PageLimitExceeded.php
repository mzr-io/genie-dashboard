<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A run would need a page beyond the page cap (Story 2.11). Nothing is kept and nothing is truncated. Code `connector.limit_exceeded`. */
final class PageLimitExceeded extends RuntimeException
{
    public readonly bool $retryable;

    public function __construct(public readonly int $cap)
    {
        $this->retryable = false;

        parent::__construct("The run would need more than {$cap} pages.");
    }

    public function code(): ErrorCode
    {
        return ErrorCode::LimitExceeded;
    }
}

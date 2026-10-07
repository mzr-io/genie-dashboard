<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** Too many wrong password confirmations (HTTP 429). */
final class ConfirmationThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many attempts.');
    }
}

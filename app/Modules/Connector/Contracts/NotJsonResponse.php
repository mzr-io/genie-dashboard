<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;
use Throwable;

/**
 * A 2xx response that is not JSON for this platform: the Content-Type is not `application/json` or `application/*+json`
 * (or names a charset other than UTF-8), it is missing, the body is empty, or the body does not parse. `reason` is a code
 * (`content_type`, `empty`, `parse`, `too_deep`), never any of the body. It is never retried and nothing is stored. The
 * error code is {@see ErrorCode::NotJson}.
 */
final class NotJsonResponse extends RuntimeException
{
    public readonly bool $retryable;

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        $this->retryable = false;

        parent::__construct("The response is not JSON ({$reason}).", 0, $previous);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::NotJson;
    }
}

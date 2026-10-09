<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * A response grew past the size limit while it was being read, counted on the decompressed stream (Story 2.6). Reading
 * stopped there: nothing partial is returned or stored, and no retry can help (`retryable` is false), because the next
 * attempt would read the same body. `bytesRead` is how many decompressed bytes had arrived when the limit was passed, not
 * necessarily the size of the whole body. The error code is {@see ErrorCode::LimitExceeded}.
 */
final class ResponseLimitExceeded extends RuntimeException
{
    public readonly bool $retryable;

    public function __construct(public readonly int $bytesRead, public readonly int $limit, public readonly ?int $status = null)
    {
        $this->retryable = false;

        parent::__construct("The response passed the limit of {$limit} bytes.");
    }

    public function code(): ErrorCode
    {
        return ErrorCode::LimitExceeded;
    }
}

<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * The source refused the credentials (Story 2.7): the token endpoint answered 400 or 401, or the API answered 401 to a call
 * made with a fresh token (after the one refresh). It is never retried (`retryable` is false): the same credentials would be
 * refused again. `reason` is a code (`token_rejected`, `api_401`), never a token, a secret or a body. The error code is
 * {@see ErrorCode::AuthFailed}.
 */
final class AuthFailed extends RuntimeException
{
    public readonly bool $retryable;

    public function __construct(public readonly string $reason)
    {
        $this->retryable = false;

        parent::__construct("The source refused the credentials ({$reason}).");
    }

    public function code(): ErrorCode
    {
        return ErrorCode::AuthFailed;
    }
}

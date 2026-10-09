<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * A token request that did not give a usable token and is not an authentication refusal: an answer that is not a 2xx, or a
 * JSON answer without a string `access_token` and a `bearer` `token_type`. It is shown as `fetch-failed`; `reason` is a code
 * (`http_5xx`, `token_invalid`), never any of the answer.
 */
final class TokenRequestFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The token request failed ({$reason}).");
    }
}

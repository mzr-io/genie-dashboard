<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * The pages could not be followed (Story 2.11) for a reason that is not a limit or a refusal: the cursor repeated (a loop),
 * a cursor that is not a plain token, or a next URL that does not parse. `reason` is a code, never a URL or a token.
 */
final class PaginationFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The pages could not be followed ({$reason}).");
    }
}

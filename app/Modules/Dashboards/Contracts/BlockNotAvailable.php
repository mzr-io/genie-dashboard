<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/**
 * A Block cannot be put on the dashboard (Story 4.9): the member cannot see it (or it does not exist: indistinguishable, 404) or it has no published
 * version (422). Nothing is placed.
 */
final class BlockNotAvailable extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const UNPUBLISHED = 'unpublished';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The block is not available.');
    }
}

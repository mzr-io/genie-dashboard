<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** An Archived Block is read-only (Story 6.1): it cannot be edited into a new Draft. HTTP 409 `blocks.block_archived`. */
final class BlockArchived extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::BlockArchived->value);
    }
}

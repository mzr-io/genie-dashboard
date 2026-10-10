<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** An undo of a slot that was changed after the override it would undo (`blocks.slot_changed`, HTTP 409); carries the current Draft. Nothing was written. */
final class SlotChanged extends RuntimeException
{
    public function __construct(public readonly Draft $current)
    {
        parent::__construct(ErrorCode::SlotChanged->value);
    }
}

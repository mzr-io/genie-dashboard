<?php

namespace App\Modules\Blocks\Contracts;

use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use RuntimeException;

/** A save was made with a stale edit-lock epoch (`platform.edit_lock_lost`, HTTP 423); carries the current state. Nothing was written. */
final class DraftLockLost extends RuntimeException
{
    public function __construct(public readonly Draft $current)
    {
        parent::__construct(PlatformErrorCode::EditLockLost->value);
    }
}

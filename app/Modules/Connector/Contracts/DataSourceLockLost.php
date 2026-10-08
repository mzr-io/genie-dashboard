<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use RuntimeException;

/** A write was made with a stale edit-lock epoch, or by a token that no longer holds the lock (`platform.edit_lock_lost`, HTTP 423); carries the current state. */
final class DataSourceLockLost extends RuntimeException
{
    public function __construct(public readonly DataSource $current)
    {
        parent::__construct(PlatformErrorCode::EditLockLost->value);
    }
}

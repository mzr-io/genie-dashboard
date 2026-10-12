<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/**
 * Publish froze the version (it is `prepared`, immutable and valid) but the second transaction failed (Story 3.19): `blocks.publish_pending`, HTTP 503.
 * Nothing is live yet. Publishing again activates it (idempotent); if nobody does, the maintenance job activates it after `precompute_timeout`.
 */
final class PublishPending extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::PublishPending->value, 0, $previous);
    }
}

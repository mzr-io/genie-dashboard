<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** The version being saved is no longer a Draft (`blocks.draft_published`, HTTP 409). Nothing was written. A discarded Draft is {@see DraftNotFound}. */
final class DraftPublished extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::DraftPublished->value);
    }
}

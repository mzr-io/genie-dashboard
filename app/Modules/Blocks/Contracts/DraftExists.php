<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** The Block already has a Draft (`blocks.draft_exists`, HTTP 409); carries that Draft's ID. */
final class DraftExists extends RuntimeException
{
    public function __construct(public readonly string $draftId)
    {
        parent::__construct(ErrorCode::DraftExists->value);
    }
}

<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A confirm or override the checklist cannot accept (nothing to confirm, a field that is missing, not one value, or of the wrong type): HTTP 422 naming the slot. */
final class SlotRefused extends RuntimeException
{
    public function __construct(public readonly string $slot, public readonly string $problem)
    {
        parent::__construct($problem);
    }
}

<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A confirm or override the checklist cannot accept (nothing to confirm, a field that is missing, not one value, or of the wrong type): HTTP 422 naming the slot. */
final class SlotRefused extends RuntimeException
{
    /** @param  list<array{path: string, display: string}>  $compatible  the fields that would fit the slot, when the refusal is a field that does not */
    public function __construct(public readonly string $slot, public readonly string $problem, public readonly array $compatible = [])
    {
        parent::__construct($problem);
    }
}

<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** The Block Type has no mappable Slot of that key (unknown, or config-only such as the icon and footer action): HTTP 404. */
final class SlotNotFound extends RuntimeException
{
    public function __construct(public readonly string $slot)
    {
        parent::__construct('This slot cannot be mapped.');
    }
}

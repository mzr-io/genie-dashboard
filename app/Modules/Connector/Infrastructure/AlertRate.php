<?php

namespace App\Modules\Connector\Infrastructure;

/** More than `threshold` blocks of one Workspace within `windowSeconds` raises the SSRF alert. */
final readonly class AlertRate
{
    public function __construct(public int $threshold, public int $windowSeconds) {}
}

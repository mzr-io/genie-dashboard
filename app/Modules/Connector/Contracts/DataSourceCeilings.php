<?php

namespace App\Modules\Connector\Contracts;

/** The platform ceilings a Data Source's own limits are checked against; null where the deployment has not set one (nothing is checked). */
final readonly class DataSourceCeilings
{
    public function __construct(
        public ?int $timeoutSeconds = null,
        public ?int $maxResponseBytes = null,
        public ?int $maxPages = null,
    ) {}
}

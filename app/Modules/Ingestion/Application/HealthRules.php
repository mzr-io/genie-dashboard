<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Infrastructure\HealthSettings;

/**
 * The valid health settings (Story 2.18). A null number leaves its rule off: the count of trailing failures needs `unreachableAfter`; the
 * success percentage needs the window and both percentages together (healthy above degraded), which {@see HealthSettings}
 * only fills when all three are valid.
 */
final readonly class HealthRules
{
    public function __construct(
        public ?int $unreachableAfter = null,
        public ?int $windowSeconds = null,
        public ?int $healthyPercent = null,
        public ?int $degradedPercent = null,
    ) {}

    public function percentRuleActive(): bool
    {
        return $this->windowSeconds !== null && $this->healthyPercent !== null && $this->degradedPercent !== null;
    }
}

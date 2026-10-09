<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * Where a Data Source's health stands (Story 2.18): `checking` (no evidence yet, never Healthy), `healthy`, `degraded` or `unreachable`,
 * and the time of its last success (ISO 8601, UTC): the newest good response of its current sync targets, or, for a source with none, its last ok probe.
 */
final readonly class SourceHealth
{
    public const CHECKING = 'checking';

    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const UNREACHABLE = 'unreachable';

    public const STATUSES = [self::CHECKING, self::HEALTHY, self::DEGRADED, self::UNREACHABLE];

    public function __construct(
        public string $status = self::CHECKING,
        public ?string $lastSuccessAt = null,
    ) {}
}

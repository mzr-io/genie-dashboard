<?php

namespace App\Modules\Connector\Contracts;

/**
 * How much raw history a Data Source keeps (Story 2.16): `latest` (the default: only the current payload, older ones once their
 * grace has passed) or `window` (history of the last `days` days; the current payload is always kept). Numbers only.
 */
final readonly class Retention
{
    public const MODES = ['latest', 'window'];

    public function __construct(
        public string $mode = 'latest',
        public ?int $days = null,
    ) {}

    public function isWindow(): bool
    {
        return $this->mode === 'window';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'days' => $this->days];
    }
}

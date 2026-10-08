<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * Where an Endpoint's scheduled fetch stands (Story 2.14): `succeeded` (with the time of the last good response), `waiting` (a target exists
 * or is about to, and no call has succeeded yet) or `not_scheduled` with the reason: `no_interval` (the refresh interval is not set, so the
 * target is never due).
 */
final readonly class SyncStatus
{
    public const SUCCEEDED = 'succeeded';

    public const WAITING = 'waiting';

    public const NOT_SCHEDULED = 'not_scheduled';

    public const NO_INTERVAL = 'no_interval';

    public function __construct(
        public string $state,
        /** ISO 8601, UTC; set when `state` is `succeeded`. A 304 or an equal body moves it (Story 2.15). */
        public ?string $lastSuccessAt = null,
        public ?string $reason = null,
        /** ISO 8601, UTC: the last attempt (Story 2.15 "Checked"), a failed one included. */
        public ?string $lastCheckedAt = null,
        /** ISO 8601, UTC: when the current payload last changed ("Data as of"); a 304 or an equal body leaves it alone. */
        public ?string $payloadChangedAt = null,
    ) {}
}

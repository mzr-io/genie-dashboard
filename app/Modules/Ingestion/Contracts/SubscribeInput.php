<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * What a subscription names (Story 2.19). `blockVersionId` is an opaque UUID and `computeContext` an opaque string (no foreign key leaves the
 * module). `periodStart` and `periodEnd` are the Workspace-local `YYYY-MM-DD` dates the period resolved to; they feed the date-bound parameters.
 *
 * `bound` maps each user-binding reference of the Endpoint (the parameter name, `header:{name}` for a header) to the value resolved for the member.
 * A reference that is absent, null or empty makes the subscription fail with `access.context_missing`. `membershipId` is required when the Endpoint
 * is scoped by the caller and counts against the new-cold-key budget.
 */
final readonly class SubscribeInput
{
    public const ROLES = ['primary', 'comparison'];

    /** @param  array<string, mixed>  $bound */
    public function __construct(
        public string $workspaceId,
        public string $dataSourceId,
        public string $endpointId,
        public string $blockVersionId,
        public string $role,
        public int $refreshIntervalSeconds,
        public string $computeContext = '',
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public array $bound = [],
        public ?string $membershipId = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpoint_id' => $this->endpointId, 'block_version_id' => $this->blockVersionId, 'role' => $this->role];
    }
}

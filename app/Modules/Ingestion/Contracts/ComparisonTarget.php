<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The comparison target linked to a primary (Story 5.6; AD-26): its ID, the sync group both share and when it last got a good response (null when it
 * never did). IDs and a time only, never a body or a value.
 */
final readonly class ComparisonTarget
{
    public function __construct(
        public string $id,
        public string $syncGroupId,
        /** ISO 8601, UTC. */
        public ?string $lastSuccessAt,
    ) {}
}

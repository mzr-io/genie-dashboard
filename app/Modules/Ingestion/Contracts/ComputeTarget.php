<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * What compute needs to know of a sync target (Story 4.2): IDs, the fetch key (the "primary fetch key" of a result key), the payload it holds
 * now and when it was last checked. Never a body or a value; read the bytes from RawStore.
 */
final readonly class ComputeTarget
{
    public function __construct(
        public string $id,
        public string $fetchKey,
        public string $endpointId,
        public string $payloadId,
        public int $payloadSeq,
        /** ISO 8601, UTC: the last good response, an unchanged one included (Story 2.15). */
        public ?string $lastSuccessAt,
        /** ISO 8601, UTC: when the current payload was first observed ("Data as of"). */
        public ?string $payloadChangedAt,
        /** The member whose own data a user-bound target holds (Story 4.13), null for shared data. */
        public ?string $ownerMembershipId = null,
    ) {}
}

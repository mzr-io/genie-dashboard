<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * One complete generation of a sync group (Story 2.20): the primary's and the comparison's payloads that were fetched together in one run and kept in
 * one transaction. IDs and sequence numbers only, never a body or a value; read the bytes from RawStore.
 */
final readonly class SyncGeneration
{
    public function __construct(
        public string $id,
        public string $syncGroupId,
        public string $primaryTargetId,
        public string $comparisonTargetId,
        public int $dispatchSeq,
        public string $primaryPayloadId,
        public string $comparisonPayloadId,
        public int $primaryPayloadSeq,
        public int $comparisonPayloadSeq,
    ) {}
}

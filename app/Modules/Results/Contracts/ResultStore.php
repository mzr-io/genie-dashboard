<?php

namespace App\Modules\Results\Contracts;

/**
 * Where Block Results live (Story 4.2): the Valkey `cache` store, every key with a TTL. A write is a compare-and-set on `payload_seq`, so two compute
 * jobs for one key that finish out of order leave the higher sequence in place, and a flushed cache simply has no result until the next compute.
 */
interface ResultStore
{
    public function get(string $workspaceId, string $key): ?BlockResult;

    /** The stored result's `payload_seq`, or null when there is none (never stored, expired or flushed). */
    public function payloadSeq(string $workspaceId, string $key): ?int;

    /**
     * Stores the result unless one with a higher `payload_seq` is there.
     *
     * @return bool false when a newer result was kept
     */
    public function put(string $workspaceId, string $key, BlockResult $result): bool;
}

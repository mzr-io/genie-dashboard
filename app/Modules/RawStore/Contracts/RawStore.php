<?php

namespace App\Modules\RawStore\Contracts;

/**
 * The raw tier (Story 2.14): the exact bytes of every good response of a sync target, and an immutable observation of each.
 * The only writer of `raw_bodies` and `raw_observations`, and only for a scheduled run of an existing target: a pasted or draft
 * sample has no target and can never enter. It runs in the caller's Workspace transaction.
 *
 * A body is `bytea`, content-addressed by `(workspace, target, sha256 of the exact bytes)`, so the same bytes stored again for
 * a target are one row. It is never decoded here: what is stored is what the source sent, number lexemes included.
 */
interface RawStore
{
    /**
     * Stores the body (once per content) and one observation of it.
     *
     * @param  int  $seq  the target's new `payload_seq`: the observation's sequence number
     * @param  int  $dispatchSeq  the dispatch the body was fetched for
     */
    public function put(string $workspaceId, string $syncTargetId, int $seq, int $dispatchSeq, #[\SensitiveParameter] string $body, ?string $requestId, \DateTimeInterface $observedAt): RawPayload;

    /** The exact bytes of a stored body of the target, or null when there is none. */
    public function get(string $workspaceId, string $syncTargetId, string $payloadId): ?string;
}

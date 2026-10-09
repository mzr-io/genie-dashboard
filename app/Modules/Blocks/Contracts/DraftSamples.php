<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The sample a Draft maps from (Story 3.8, the paste half). One per Draft: a new accepted paste replaces the old one. It is kept only in
 * `draft_samples`, sealed with the workspace `data` key, with an end time from `tunables.timeouts.draft_sample_ttl`, and never in the raw
 * tier or on a version. The text is read only by the platform's lossless JSON decoder, so a number keeps every digit.
 */
interface DraftSamples
{
    /** The origin of a pasted sample. */
    public const ORIGIN = 'pasted';

    /** Reads the text and says whether it is acceptable (limits and grammar) and what it holds. Stores nothing and needs no Draft. */
    public function check(string $text): SampleCheck;

    /**
     * Accepts and stores a pasted sample for the Draft, replacing the one it has.
     *
     * @param  (\Closure(): bool)|null  $holdsLock  checks, inside the transaction, that the caller's lock token still holds the soft lock
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws DraftPublished the version is no longer a Draft
     * @throws DraftLockLost the epoch is stale or the token does not hold the lock
     * @throws SampleRejected not stored: no acknowledgement, a limit or the TTL unset, over a limit, or not JSON
     * @throws SampleUnavailable the sample could not be sealed
     */
    public function store(DraftActor $actor, string $draftId, string $text, bool $containsPersonalData, int $lockEpoch, ?\Closure $holdsLock = null): StoredSample;

    /**
     * The Draft's sample, or null when it has none or it has ended.
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws SampleUnavailable the stored sample cannot be opened
     */
    public function find(string $workspaceId, string $draftId): ?StoredSample;
}

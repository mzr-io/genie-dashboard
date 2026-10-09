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

    /** The origin of a sample fetched from the API (Story 3.9); stored as source `fetch`. */
    public const ORIGIN_FETCHED = 'fetched';

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
     * Stores a sample fetched from the API (Story 3.9), replacing the one the Draft has and raising its `sample_revision`. Called by the Operation
     * that fetched it, in the Workspace's transaction: the Draft must still be at the lock epoch the fetch started under, the same limits and
     * sealing as a paste apply, and nothing is stored on any refusal.
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws DraftPublished the version is no longer a Draft
     * @throws DraftLockLost the epoch moved since the fetch started
     * @throws SampleRejected a limit or the TTL is unset, or over a limit
     * @throws SampleUnavailable the sample could not be sealed
     */
    public function storeFetched(DraftActor $actor, string $draftId, string $text, int $lockEpoch, int $status, int $latencyMs, string $requestKey): StoredSample;

    /**
     * The Draft's sample, or null when it has none or it has ended.
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws SampleUnavailable the stored sample cannot be opened
     */
    public function find(string $workspaceId, string $draftId): ?StoredSample;

    /**
     * The `sample_revision` of the Draft's live sample, or null when it has none or it has ended. It opens nothing and parses nothing, so a
     * caller that holds the decoded sample of this revision can skip {@see self::find()} (Story 3.10).
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     */
    public function revision(string $workspaceId, string $draftId): ?int;
}

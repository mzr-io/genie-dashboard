<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Platform\Operations\Operation;

/**
 * Fetch from API (Story 3.9): starts the `draft_sample_fetch` Operation that fetches a sample for a Draft's Endpoint with the Draft's parameters.
 * The request runs on `worker-connector` through the egress guard (the Connector's machinery of Story 2.10); the body reaches the Draft only as
 * the Operation's encrypted, short-lived blob, which the Operation's handler stores in `draft_samples` (origin fetched), sealed and limited exactly
 * as a pasted sample is. Only the membership that started the Operation can read it.
 */
interface DraftSampleFetches
{
    public const KIND = 'draft_sample_fetch';

    public const QUEUE = 'fetch-interactive';

    /** The setting that caps how long a fetch may run (and so how long its Operation lives): `tunables.guards.platform_timeout_ceiling`, whole seconds. */
    public const CEILING_ENV = 'DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING';

    /**
     * Checks everything before anything is counted or queued, in this order: the Draft (404), the edit-lock claim (423), the personal-data
     * acknowledgement, the limits, the sample TTL and the timeout ceiling (each unset one is a refusal that names it), the Endpoint and its
     * values (422) and the rate limit (429). Then enqueues the Operation with the Draft's revision as its `subject_revision`.
     *
     * @param  array<string, string|null>  $bindings  the Fixed parameters' own values
     * @param  array{preset: string, from?: string, to?: string}|null  $defaultRange  the form's Default date range choice; the server resolves its dates (Story 4.1)
     * @param  string  $requestKey  the client's key of this request, kept with the sample to tell later that it belongs to earlier parameters
     * @param  (\Closure(): bool)|null  $holdsLock  checks, inside the transaction, that the caller's lock token still holds the soft lock
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws SampleRejected not started: no acknowledgement, a setting unset, or the Endpoint is gone
     * @throws InvalidDataSource 422, a field error naming the parameter as `values.{name}`
     * @throws SampleFetchThrottled
     */
    public function start(DraftActor $actor, string $draftId, string $dataSourceId, string $endpointId, array $bindings, ?array $defaultRange, string $requestKey, bool $containsPersonalData, int $lockEpoch, ?\Closure $holdsLock = null): Operation;
}

<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Platform\Operations\Operation;

/**
 * Validate against the real API (Story 3.18, wizard step 5): the `publish_validation` Operation that proves a mapped Draft against the live
 * response of its Endpoint, and the tenant-scoped ValidationReport it stores, bound to the Draft's revision. A pasted sample is never proof.
 * Fail-fast: a Draft with strict-schema errors is reported `failed` with the list and no request is made.
 */
interface DraftValidations
{
    public const KIND = 'publish_validation';

    public const QUEUE = 'fetch-interactive';

    /**
     * Starts a validation of the Draft at its current revision (the Operation's `subject_revision`). In order: the Draft (404, 409 once
     * published), the edit-lock claim (423), the lifetime setting, a real Data Source and Endpoint, then either the fail-fast path (strict-schema
     * errors: queued without a request and without counting a fetch) or the fetch's checks and rate limit (422, 429).
     *
     * @param  (\Closure(): bool)|null  $holdsLock  checks, inside the transaction, that the caller's lock token still holds the soft lock
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws ValidationRefused
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws InvalidDataSource
     * @throws SampleFetchThrottled
     */
    public function start(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock = null): Operation;

    /**
     * The Draft's latest report, or null when it was never validated.
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     */
    public function latest(string $workspaceId, string $draftId): ?ValidationReport;
}

<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Platform\Operations\Operation;

/**
 * Fetch as user from the Create block wizard (Story 3.17), glue over the Connector's Operation of Story 2.13. The response is the Operation's
 * own encrypted, short-lived blob, readable only by the Admin that started it: it is never written to `draft_samples` or the shared Draft, the
 * member's attribute values are resolved by the worker and never leave it, and the browser gets only the value-free structure ({@see UserSampleView}).
 * Like the preview it takes no edit lock and writes nothing to the Draft.
 */
interface DraftUserSamples
{
    /**
     * Starts the Fetch as user for a Draft's Endpoint choices (one audit event, and the outbox event the notifications epic delivers).
     *
     * @param  array<string, string|null>  $bindings  the Fixed parameters' own values
     * @param  array{preset: string, from?: string, to?: string}|null  $defaultRange  the form's Default date range choice; the server resolves its dates (Story 4.1)
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws MembershipNotFound
     * @throws InvalidDataSource
     * @throws SampleFetchThrottled
     */
    public function start(DraftActor $actor, string $draftId, string $dataSourceId, string $endpointId, string $membershipId, array $bindings, ?array $defaultRange): Operation;

    /**
     * The structure of a finished Fetch as user, or null when it is gone (expired, not the requester's, failed, or the Endpoint moved on).
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws SamplePreviewDenied the reader no longer holds `data.preview_as_user`
     */
    public function view(DraftActor $actor, string $draftId, UserSampleRef $ref): ?UserSampleView;
}

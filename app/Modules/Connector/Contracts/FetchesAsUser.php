<?php

namespace App\Modules\Connector\Contracts;

use App\Modules\Access\Contracts\MembershipNotFound;
use App\Platform\Operations\Operation;

/**
 * Starts a Fetch as user (Story 2.13): an Operation of kind `fetch_as_user` that `worker-connector` runs on the same queue as
 * {@see SampleFetches}, with the same lifetime and the same sealed blob store, so no second unencrypted path to a response exists.
 * The Admin chooses a member; the server alone resolves that member's ID, email, group and attributes ({@see UserContextUnresolved}
 * when one cannot be produced), and the response is read back, only by the requester, through the Sample route ({@see Samples}).
 * Nothing is stored in `draft_samples`, `raw_bodies` or PostgreSQL.
 */
interface FetchesAsUser
{
    public const KIND = 'fetch_as_user';

    public const QUEUE = SampleFetches::QUEUE;

    /** The `sync_runs` kind of an attempt. */
    public const RUN_KIND = 'fetch_as_user';

    /**
     * Before anything is queued: the target must be an active member of the Workspace, the non-bound values are checked with the
     * rules of Test endpoint (a value for a user-bound name is refused), the rate limits are counted, and `connector.fetch_as_user.performed`
     * is audited and emitted to the outbox (IDs only) in the transaction that enqueues the Operation.
     *
     * @param  array<string, mixed>  $values  parameter name => value, for the parameters that are not user-bound
     *
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws MembershipNotFound the target is not an active member of this Workspace (404)
     * @throws InvalidDataSource 422, `values.{name}`
     * @throws SampleFetchThrottled
     */
    public function start(DataSourceActor $actor, string $dataSourceId, string $endpointId, string $targetMembershipId, array $values): Operation;
}

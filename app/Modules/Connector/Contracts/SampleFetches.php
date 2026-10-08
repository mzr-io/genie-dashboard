<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Operations\Operation;

/**
 * Starts a test of an Endpoint (Story 2.10): an Operation of kind `sample_fetch` that `worker-connector` runs on queue
 * `fetch-interactive` as the requester's Workspace. The Ingestion module does not exist yet and the Operations registry is
 * module-agnostic, so the Connector registers the kind; a later story can move the handler. The server renders the request
 * from the Endpoint's current revision and the Admin's test values; the response body is handed back only to the requester
 * (see {@see Samples}) and is never written to PostgreSQL.
 */
interface SampleFetches
{
    public const KIND = 'sample_fetch';

    public const QUEUE = 'fetch-interactive';

    /** The user code and the `sync_runs` kind of an attempt. */
    public const RUN_KIND = 'sample_fetch';

    /**
     * Before anything is queued: every declared parameter must have a value (a fixed parameter's stored value is the default; a
     * date range or period parameter needs an ISO `YYYY-MM-DD` date), each value is checked with the rules of a save, the rate
     * limits are counted and the test is audited as `connector.endpoint.tested` in the transaction that enqueues it.
     *
     * @param  array<string, mixed>  $values  parameter name => test value (a header bound to a date is keyed `header:{name}`)
     *
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws InvalidDataSource 422, a field error naming the parameter as `values.{name}`
     * @throws SampleFetchThrottled when the person or the Workspace is over its limit
     */
    public function start(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $values): Operation;
}

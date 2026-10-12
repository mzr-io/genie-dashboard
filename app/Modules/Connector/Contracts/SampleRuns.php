<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationOutcome;

/**
 * An Endpoint fetch run on behalf of another module's Operation (Story 3.9: the Blocks wizard's Fetch from API). The Connector owns the
 * request: it renders it from the Endpoint's current revision, sends it through the guard and the transport, records the run and, on
 * success, keeps the body only as the encrypted, short-lived blob of the Operation ({@see self::take()}), never in PostgreSQL.
 */
interface SampleRuns
{
    /**
     * Checks a fetch before anything is queued and counts it: the Data Source and the Endpoint are the Workspace's, only a GET or a read-only
     * POST is sent, an Endpoint that needs user context is refused, every declared parameter has a valid value (Fixed ones from `$bindings`, else
     * the Endpoint's own; date-range rows from `$range`, period rows from `$period`) and the rate limits are counted. The rate limit and
     * its window must be set.
     *
     * @param  array<string, string|null>  $bindings  the Fixed parameters' own values
     * @param  array{from: string, to: string}|null  $range  the default date range's dates (ISO `YYYY-MM-DD`)
     * @param  array{start: string, end: string}|null  $period  the Block's own period, when it has one
     *
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws InvalidDataSource 422, a field error naming the parameter as `values.{name}`
     * @throws SampleFetchNotConfigured a setting the fetch needs is unset
     * @throws SampleFetchThrottled
     */
    public function prepare(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period): PreparedSampleFetch;

    /** Gives back the rate-limit slot {@see self::prepare()} took, when the fetch it counted was not queued. */
    public function release(DataSourceActor $actor): void;

    /**
     * Sends the request for the Operation (on `worker-connector`). The Operation's own id, requester and lifetime name the blob; the outcome is
     * the summary of a Test endpoint (`ok`, `status`, `latency_ms`, `code`, `reason`, `size_bytes`, `limit_bytes`, `host`, `request_id`, ...).
     * A stale outcome means the Endpoint moved on from `$endpointRevision`; nothing was kept then.
     *
     * @param  array<string, string>  $values
     */
    public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values): OperationOutcome;

    /** The body the run kept for the Operation's requester, or null (never kept, expired, another requester or revision). */
    public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string;

    /** Drops the Operation's blob, if any. */
    public function discard(Operation $operation): void;
}

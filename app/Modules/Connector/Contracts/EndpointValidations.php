<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Operations\Operation;

/**
 * An Endpoint fetched live to validate a Draft before publish (Story 3.18), on behalf of another module's Operation. It reuses the Connector's
 * machinery of Story 2.10 whole (value checks, the egress guard and transport, the error ladder, the `sync_runs` row, the shared rate-limit
 * counters and the sealed Valkey blob); there is no second fetcher. Unlike {@see SampleRuns}, an Endpoint that requires user context is accepted:
 * it is checked unbound first (the platform must refuse it, no request made) and then fetched as the requester.
 */
interface EndpointValidations
{
    /**
     * Checks a validation before anything is queued and counts it against the rate limits.
     *
     * @param  array<string, string|null>  $bindings  the Fixed parameters' own values
     * @param  array{from: string, to: string}|null  $range
     * @param  array{start: string, end: string}|null  $period
     * @param  array{from: string, to: string}|null  $comparisonRange  the prior window of a prior-period comparison (Story 5.6b): its request is checked too
     * @param  array{start: string, end: string}|null  $comparisonPeriod
     *
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws InvalidDataSource
     * @throws SampleFetchNotConfigured
     * @throws SampleFetchThrottled
     */
    public function prepare(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period, ?array $comparisonRange = null, ?array $comparisonPeriod = null): PreparedEndpointValidation;

    /** Gives back the rate-limit slot {@see self::prepare()} took, when the validation it counted was not queued. */
    public function release(DataSourceActor $actor): void;

    /**
     * Fetches the Endpoint for the Operation (on `worker-connector`). A stale outcome means the Endpoint moved on from `$endpointRevision`.
     *
     * @param  array<string, string>  $values
     */
    public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound): EndpointValidationRun;

    /** The body the run kept for the Operation's requester, or null. */
    public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string;

    /** Drops the Operation's blob, if any. */
    public function discard(Operation $operation): void;
}

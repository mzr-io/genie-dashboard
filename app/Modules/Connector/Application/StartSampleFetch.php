<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Infrastructure\SampleFetchSettings;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Cache\RateLimiter;

/**
 * Starts a test of an Endpoint (Story 2.10). In order, before anything is written: the Data Source and the Endpoint must be
 * the Workspace's, only a GET or a read-only POST is ever tested, every declared parameter needs a valid value
 * ({@see RenderEndpointRequest::values()}), then the rate limits per membership and per Workspace are counted (a refused
 * request is not counted) and only then is the Operation enqueued, with the Endpoint's current `revision` as its subject
 * revision. The test is audited as `connector.endpoint.tested` in the same transaction as the enqueue: a rollback leaves
 * neither. The audit event holds the Endpoint id, the method and the revision, never a value, the path or a body.
 *
 * The queued job carries the test values (they are test data, not secrets, and are kept out of every log, audit row, `sync_runs`
 * row and Operation summary); `worker-connector` renders the request itself.
 */
final class StartSampleFetch implements SampleFetches
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Operations $operations,
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly SampleFetchSettings $limits,
        private readonly RateLimiter $limiter,
        private readonly Audit $audit,
    ) {}

    public function start(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $values): Operation
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $dataSourceId, $endpointId, $values): Operation {
            $this->sources->find($actor->workspaceId, $dataSourceId);
            $endpoint = $this->endpoints->find($actor->workspaceId, $dataSourceId, $endpointId);

            if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
                throw new InvalidDataSource(['endpoint' => ['Only a GET or a read-only POST can be tested.']], ['endpoint' => 'post-readonly-required']);
            }

            $resolved = $this->renderer->values($endpoint, $values);
            $this->hitLimits($actor);

            $operation = $this->operations->enqueue(
                $actor->workspaceId,
                self::KIND,
                $actor->membershipId,
                'endpoint',
                $endpoint->id,
                $endpoint->revision,
                ['data_source_id' => $endpoint->dataSourceId, 'values' => $resolved],
            );

            $this->audit->record(
                AuditAction::ConnectorEndpointTested,
                [
                    'endpoint_id' => $endpoint->id,
                    'data_source_id' => $endpoint->dataSourceId,
                    'method' => strtolower($endpoint->method),
                    'endpoint_revision' => $endpoint->revision,
                ],
                subject: 'endpoint:'.$endpoint->id,
                actor: $actor->membershipId,
            );

            return $operation;
        });
    }

    /**
     * Counts this request against every limit first (an atomic increment), then compares: concurrent requests cannot all pass a
     * check that none of them has counted yet. A request over a limit is refused with the wait. Same rule as Test connection.
     */
    private function hitLimits(DataSourceActor $actor): void
    {
        $window = $this->limits->window();
        $retry = 0;

        foreach ([
            [TenantKey::cache($actor->workspaceId, 'sample-fetch:membership:'.strtolower($actor->membershipId)), $this->limits->membershipLimit()],
            [TenantKey::cache($actor->workspaceId, 'sample-fetch:workspace'), $this->limits->workspaceLimit()],
        ] as [$key, $max]) {
            if ($window === null || $max === null) {
                continue;
            }

            if ($this->limiter->hit($key, $window) > $max) {
                // At least a second: the window may roll over between the hit and the question, and the request is still refused.
                $retry = max($retry, 1, $this->limiter->availableIn($key));
            }
        }

        if ($retry > 0) {
            throw new SampleFetchThrottled($retry);
        }
    }
}

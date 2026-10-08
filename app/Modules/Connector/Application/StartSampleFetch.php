<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\WorkspaceTransaction;

/**
 * Starts a test of an Endpoint (Story 2.10). In order, before anything is written: the Data Source and the Endpoint must be
 * the Workspace's, only a GET or a read-only POST is ever tested, every declared parameter needs a valid value
 * ({@see RenderEndpointRequest::values()}), then the rate limits per membership and per Workspace are counted (a refused
 * request is not counted) and only then is the Operation enqueued, with the Endpoint's current `revision` as its subject
 * revision. The test is audited as `connector.endpoint.tested` in the same transaction as the enqueue: a rollback leaves
 * neither. The audit event holds the Endpoint id, the method and the revision, never a value, the path or a body.
 *
 * An Endpoint that requires user context is refused (422, reason `requires-user-context`, nothing queued): its values come from a
 * member, so only Fetch as user ({@see StartFetchAsUser}) can run it.
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
        private readonly SampleFetchRateLimit $limits,
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

            // A client value for a user-bound name is refused here too (422 `values.{name}`).
            $resolved = $this->renderer->values($endpoint, $values);

            if ($endpoint->requiresUserContext) {
                // A blank in place of the user's value would look like a working shared fetch: only Fetch as user can run this Endpoint.
                throw new InvalidDataSource(['endpoint' => ['This endpoint uses user context. Use Fetch as user to see what a member would get.']], ['endpoint' => 'requires-user-context']);
            }

            $this->limits->hit($actor);

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
}

<?php

namespace App\Modules\Connector\Application;

use App\Modules\Access\Contracts\UserContext;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;

/**
 * Starts a Fetch as user (Story 2.13). In order, before anything is written: the Data Source and the Endpoint must be the
 * Workspace's, only a GET or a read-only POST is ever sent, the target must be an active member of the Workspace (anything else is a
 * 404; row-level security hides another Workspace's members), the non-bound values are checked as Test endpoint checks them (a
 * `values` entry for a user-bound name is a 422 `values.{name}`), then the rate limits shared with Test endpoint are counted and only
 * then is the Operation enqueued, with the Endpoint's current `revision` as its subject revision.
 *
 * The audit event `connector.fetch_as_user.performed` (Endpoint id, target membership id, revision) and the outbox event of the same
 * name (IDs only, for the notifications epic to deliver) are written in the same transaction as the enqueue: a rollback leaves
 * neither. The queued job carries the target's ID and the non-bound values; every value taken from the target is resolved by
 * `worker-connector` ({@see RunFetchAsUser}) and never travels in the job.
 */
final class StartFetchAsUser implements FetchesAsUser
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Operations $operations,
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly SampleFetchRateLimit $limits,
        private readonly UserContext $members,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function start(DataSourceActor $actor, string $dataSourceId, string $endpointId, string $targetMembershipId, array $values): Operation
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $dataSourceId, $endpointId, $targetMembershipId, $values): Operation {
            $this->sources->find($actor->workspaceId, $dataSourceId);
            $endpoint = $this->endpoints->find($actor->workspaceId, $dataSourceId, $endpointId);

            // An active member of this Workspace, or MembershipNotFound (a 404). Nothing is resolved yet: no binding is asked for.
            $this->members->resolve($actor->workspaceId, $targetMembershipId, []);
            $target = strtolower($targetMembershipId);

            if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
                throw new InvalidDataSource(['endpoint' => ['Only a GET or a read-only POST can be fetched.']], ['endpoint' => 'post-readonly-required']);
            }

            $resolved = $this->renderer->values($endpoint, $values);
            $this->limits->hit($actor);

            $operation = $this->operations->enqueue(
                $actor->workspaceId,
                self::KIND,
                $actor->membershipId,
                'endpoint',
                $endpoint->id,
                $endpoint->revision,
                ['data_source_id' => $endpoint->dataSourceId, 'target_membership_id' => $target, 'values' => $resolved],
            );

            $facts = [
                'endpoint_id' => $endpoint->id,
                'data_source_id' => $endpoint->dataSourceId,
                'target_membership_id' => $target,
                'endpoint_revision' => $endpoint->revision,
            ];

            $this->audit->record(
                AuditAction::ConnectorFetchAsUserPerformed,
                $facts,
                subject: 'endpoint:'.$endpoint->id,
                actor: $actor->membershipId,
            );
            $this->outbox->emit(
                AuditAction::ConnectorFetchAsUserPerformed,
                'membership:'.$target,
                $facts,
                actor: $actor->membershipId,
            );

            return $operation;
        });
    }
}

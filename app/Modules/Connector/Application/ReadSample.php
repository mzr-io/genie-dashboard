<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\Sample;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Modules\Connector\Contracts\Samples;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Platform\Operations\Operations;
use App\Platform\Operations\OperationStatus;

/**
 * Reads the Sample Response of an Endpoint test or a Fetch as user (Stories 2.10, 2.13: kinds `sample_fetch` and `fetch_as_user`) for the membership that asked and nobody else. Everything that
 * is not that reads as null: another member, another Workspace, an Operation of another Endpoint or kind, one that failed,
 * is stale or has expired, an Endpoint that moved on from the revision tested, and a blob that is gone or cannot be opened
 * are indistinguishable, so the existence of a sample is not disclosed.
 */
final class ReadSample implements Samples
{
    public function __construct(
        private readonly Operations $operations,
        private readonly Endpoints $endpoints,
        private readonly SampleBlobStore $blobs,
    ) {}

    public function read(string $workspaceId, string $membershipId, string $dataSourceId, string $endpointId, string $operationId, bool $mayPreviewAsUser = false): ?Sample
    {
        $operation = $this->operations->status($workspaceId, $operationId, $membershipId);

        if ($operation === null
            || ! in_array($operation->kind, [SampleFetches::KIND, FetchesAsUser::KIND], true)
            || $operation->subjectType !== 'endpoint'
            || $operation->subjectId !== strtolower($endpointId)
            || $operation->subjectRevision === null
            || $operation->status !== OperationStatus::Succeeded
            || ($operation->result['ok'] ?? false) !== true) {
            return null;
        }

        // Everything above is the requester's own: only now is a lost permission told apart from a missing sample.
        if ($operation->kind === FetchesAsUser::KIND && ! $mayPreviewAsUser) {
            throw new SamplePreviewDenied;
        }

        try {
            $endpoint = $this->endpoints->find($workspaceId, $dataSourceId, $endpointId);
        } catch (DataSourceNotFound|EndpointNotFound) {
            return null;
        }

        if ($endpoint->revision !== $operation->subjectRevision) {
            return null;
        }

        $body = $this->blobs->get($workspaceId, $operation->id, $operation->requesterMembershipId, $endpoint->id, $operation->subjectRevision);
        $status = $operation->result['status'] ?? null;
        $latency = $operation->result['latency_ms'] ?? null;

        if ($body === null || ! is_int($status) || ! is_int($latency)) {
            return null;
        }

        return new Sample($status, $latency, $body, $operation->expiresAt);
    }
}

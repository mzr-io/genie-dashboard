<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\EndpointValidationRun;
use App\Modules\Connector\Contracts\EndpointValidations;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\PreparedEndpointValidation;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleFetchNotConfigured;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Modules\Connector\Infrastructure\SampleFetchSettings;
use App\Platform\Operations\Operation;

/**
 * The Connector's side of validating a Draft against the real API (Story 3.18, {@see EndpointValidations}). It is the machinery of Story 3.9
 * ({@see RunDraftSampleFetch}) with one difference: an Endpoint that requires user context is accepted. It is then fetched twice. First without
 * the member's attribute, through {@see RunSampleFetch::execute()} with no binder, which the platform must refuse before any request is made
 * (`access.context_missing`); an attempt that is not refused means the Endpoint could be published unbound and the validation fails. Then as the
 * requester, through {@see RunFetchAsUser}, so the shape is proved with a member's own values that never leave the worker.
 */
final class RunEndpointValidation implements EndpointValidations
{
    public function __construct(
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly SampleFetchRateLimit $limits,
        private readonly SampleFetchSettings $settings,
        private readonly RunSampleFetch $fetch,
        private readonly RunFetchAsUser $asUser,
        private readonly SampleBlobStore $blobs,
    ) {}

    public function prepare(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period, ?array $comparisonRange = null, ?array $comparisonPeriod = null): PreparedEndpointValidation
    {
        $this->sources->find($actor->workspaceId, $dataSourceId);
        $endpoint = $this->endpoints->find($actor->workspaceId, $dataSourceId, $endpointId);

        if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
            throw new InvalidDataSource(['endpoint' => ['Only a GET or a read-only POST can be fetched.']], ['endpoint' => 'post-readonly-required']);
        }

        $values = $this->renderer->values($endpoint, $this->renderer->given($endpoint, $bindings, $range, $period));
        $comparison = $comparisonRange === null && $comparisonPeriod === null
            ? null
            : $this->renderer->values($endpoint, $this->renderer->given($endpoint, $bindings, $comparisonRange, $comparisonPeriod));
        $missing = $this->settings->missingForDraftFetch();

        if ($missing !== null) {
            throw new SampleFetchNotConfigured($missing);
        }

        $this->limits->hit($actor);

        return new PreparedEndpointValidation($endpoint->dataSourceId, $endpoint->id, $endpoint->revision, $values, $endpoint->requiresUserContext, $comparison);
    }

    public function release(DataSourceActor $actor): void
    {
        $this->limits->release($actor);
    }

    public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound): EndpointValidationRun
    {
        // The same Operation (its id names the blob), seen as a test of the Endpoint at the revision that was prepared.
        $view = new Operation(
            $operation->id, $operation->workspaceId, SampleFetches::KIND, $operation->requesterMembershipId, 'endpoint', strtolower($endpointId),
            $endpointRevision, $operation->status, $operation->expiresAt, $operation->requestId, $operation->result,
        );
        $input = ['data_source_id' => $dataSourceId, 'values' => $values];

        if (! $userBound) {
            return new EndpointValidationRun($this->fetch->execute($view, $input, null));
        }

        $negative = $this->fetch->execute($view, $input, null, true);

        if ($negative->stale) {
            return new EndpointValidationRun($negative, null);
        }

        $summary = $negative->summary;
        $refused = ! $negative->succeeded && ($summary['code'] ?? null) === ConnectionTestCode::ContextMissing->value && ($summary['status'] ?? null) === null;

        if (! $refused) {
            $this->blobs->forget($operation->workspaceId, $operation->id);

            // Only an answer with an HTTP status proves a request went out without the member's attribute. Any other failure (egress, a
            // setting, a secret, not read-only) is that failure, reported with its own reason.
            return new EndpointValidationRun($negative, ($summary['status'] ?? null) !== null ? false : null);
        }

        return new EndpointValidationRun($this->asUser->handle($view, $input + ['target_membership_id' => $operation->requesterMembershipId]), true);
    }

    public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string
    {
        return $this->blobs->get($operation->workspaceId, $operation->id, $operation->requesterMembershipId, $endpointId, $endpointRevision);
    }

    public function discard(Operation $operation): void
    {
        $this->blobs->forget($operation->workspaceId, $operation->id);
    }
}

<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\PreparedSampleFetch;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleFetchNotConfigured;
use App\Modules\Connector\Contracts\SampleRuns;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Modules\Connector\Infrastructure\SampleFetchSettings;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationOutcome;

/**
 * The Connector's side of the wizard's Fetch from API (Story 3.9, {@see SampleRuns}). It reuses the machinery of Story 2.10 whole: the checks of
 * {@see RenderEndpointRequest::values()} and {@see StartSampleFetch}, the shared rate limit counters ({@see SampleFetchRateLimit}), the run and
 * its error ladder ({@see RunSampleFetch::execute()}) and the sealed blob ({@see SampleBlobStore}). The Operation that carries it belongs to
 * another module, so the run is made against a view of it that names the Endpoint as its subject.
 */
final class RunDraftSampleFetch implements SampleRuns
{
    public function __construct(
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly SampleFetchRateLimit $limits,
        private readonly SampleFetchSettings $settings,
        private readonly RunSampleFetch $fetch,
        private readonly SampleBlobStore $blobs,
    ) {}

    public function prepare(DataSourceActor $actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period): PreparedSampleFetch
    {
        $this->sources->find($actor->workspaceId, $dataSourceId);
        $endpoint = $this->endpoints->find($actor->workspaceId, $dataSourceId, $endpointId);

        if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
            throw new InvalidDataSource(['endpoint' => ['Only a GET or a read-only POST can be fetched.']], ['endpoint' => 'post-readonly-required']);
        }

        if ($endpoint->requiresUserContext) {
            // The same refusal as Test endpoint: a blank in place of the user's value would look like a working shared fetch.
            throw new InvalidDataSource(['endpoint' => ['This endpoint uses user context. Use Fetch as user to see what a member would get.']], ['endpoint' => 'requires-user-context']);
        }

        $values = $this->renderer->values($endpoint, $this->given($endpoint, $bindings, $range, $period));
        $missing = $this->settings->missingForDraftFetch();

        if ($missing !== null) {
            throw new SampleFetchNotConfigured($missing);
        }

        $this->limits->hit($actor);

        return new PreparedSampleFetch($endpoint->dataSourceId, $endpoint->id, $endpoint->revision, $values);
    }

    public function release(DataSourceActor $actor): void
    {
        $this->limits->release($actor);
    }

    public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values): OperationOutcome
    {
        // The same Operation (its id names the blob), seen as a test of the Endpoint at the revision that was prepared.
        $view = new Operation(
            $operation->id, $operation->workspaceId, SampleFetches::KIND, $operation->requesterMembershipId, 'endpoint', strtolower($endpointId),
            $endpointRevision, $operation->status, $operation->expiresAt, $operation->requestId, $operation->result,
        );

        return $this->fetch->handle($view, ['data_source_id' => $dataSourceId, 'values' => $values]);
    }

    public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string
    {
        return $this->blobs->get($operation->workspaceId, $operation->id, $operation->requesterMembershipId, $endpointId, $endpointRevision);
    }

    public function discard(Operation $operation): void
    {
        $this->blobs->forget($operation->workspaceId, $operation->id);
    }

    /**
     * What the Admin's choices give each parameter and header: a Fixed one its own value when the block has one (else the Endpoint's), a date-range
     * one its date, a period one its date when the block has a period. A user-bound row is never given a value.
     *
     * @param  array<string, string|null>  $bindings
     * @param  array{from: string, to: string}|null  $range
     * @param  array{start: string, end: string}|null  $period
     * @return array<string, string>
     */
    private function given(Endpoint $endpoint, array $bindings, ?array $range, ?array $period): array
    {
        $given = [];
        $date = fn (string $binding): ?string => match ($binding) {
            'date_range_from' => $range['from'] ?? null,
            'date_range_to' => $range['to'] ?? null,
            'period_start' => $period['start'] ?? null,
            'period_end' => $period['end'] ?? null,
            default => null,
        };

        foreach ($endpoint->params as $param) {
            $value = $param['binding'] === 'fixed' ? ($bindings[$param['name']] ?? null) : (in_array($param['binding'], EndpointInput::DATE_BINDINGS, true) ? $date($param['binding']) : null);

            if (is_string($value) && $value !== '') {
                $given[$param['name']] = $value;
            }
        }

        foreach ($endpoint->headers as $header) {
            $value = in_array($header['binding'], EndpointInput::DATE_BINDINGS, true) ? $date($header['binding']) : null;

            if (is_string($value) && $value !== '') {
                $given['header:'.$header['name']] = $value;
            }
        }

        return $given;
    }
}

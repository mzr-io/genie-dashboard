<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SecretVault;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduled fetch of one Endpoint revision (Story 2.14), for the Ingestion `FetchJob` on `worker-connector`. It is the Endpoint test's
 * path ({@see RunSampleFetch}) without the Operation and the sealed blob: render the request from the revision and the target's values
 * ({@see RenderEndpointRequest}), send it through the {@see FetchTransport} (so through the guard, pagination and credentials) and judge
 * the answer with the shared error ladder ({@see EndpointFetchLadder}). A 2xx answer must be JSON that parses losslessly; its exact text is
 * handed back, and nothing is stored or decoded here.
 *
 * The revisions are checked before anything is sent: an Endpoint or Data Source that has moved on since the target was registered is
 * reported as `moved` and nothing is called. An Endpoint that needs user context is never fetched here, and a POST only when read-only
 * (the Idempotency-Key is the run's id). The result and every log line carry codes, counts and ids, never a URL, a value, a header or a body.
 */
final class FetchEndpoint implements EndpointFetcher
{
    public function __construct(
        private readonly FetchTransport $transport,
        private readonly SecretVault $vault,
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly EndpointFetchLadder $ladder,
    ) {}

    public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
    {
        $status = $latencyMs = $bytes = $limitBytes = $page = $pages = $code = $reason = null;
        $urlTemplate = '';
        $names = [];
        $began = hrtime(true);

        try {
            $source = $this->sources->find($spec->workspaceId, $spec->dataSourceId);
            $endpoint = $this->endpoints->find($spec->workspaceId, $spec->dataSourceId, $spec->endpointId);
            $urlTemplate = rtrim($source->baseUrl, '/').$endpoint->pathTemplate;
            $names = array_column($endpoint->params, 'name');

            foreach ($endpoint->headers as $header) {
                $names[] = 'header:'.$header['name'];
            }

            if ($endpoint->revisionId !== $spec->endpointRevisionId || $source->revision !== $spec->dataSourceRevision) {
                // Revised since the target was registered: nothing is sent for a revision nobody asked for now.
                return new EndpointFetchResult(false, null, null, null, null, null, 'revision_moved', $urlTemplate, $names, moved: true, dataSourceId: $source->id);
            }

            if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'not_read_only';
            } elseif ($endpoint->requiresUserContext) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'user_context_required';
            } else {
                $values = $this->renderer->values($endpoint, $spec->values);
                $request = $this->renderer->request($spec->workspaceId, $source, $endpoint, $values, $this->vault->status($spec->workspaceId, $source->id), $spec->runId);
                $urlTemplate = $request->urlTemplate;
                $began = hrtime(true);
                $response = $this->transport->fetch($request);

                if ($request->pagination->enabled()) {
                    $page = $response->page;
                    $pages = $response->pages;
                }

                $status = $response->status;
                $latencyMs = $response->latencyMs;
                $bytes = $response->bytes;

                if (! $response->successful()) {
                    $code = ConnectionTestCode::FetchFailed;
                    $reason = 'http_'.$status;
                } else {
                    // A 2xx answer must be JSON that parses, losslessly; the value is dropped, the exact text is what is kept.
                    $response->assertJson();

                    return new EndpointFetchResult(true, $response->body, $status, $latencyMs, $bytes, null, null, $urlTemplate, $names, page: $page, pages: $pages, dataSourceId: $source->id);
                }
            }
        } catch (InvalidDataSource) {
            // The target's values no longer fit the revision they were registered for.
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'values_invalid';
        } catch (DataSourceNotFound|EndpointNotFound) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'endpoint_gone';
        } catch (Throwable $e) {
            $failure = $this->ladder->classify($e, $began, ['workspace_id' => $spec->workspaceId, 'endpoint_id' => $spec->endpointId], 'connector.scheduled_fetch');
            $code = $failure->code;
            $reason = $failure->reason;
            $latencyMs = $failure->latencyMs ?? $latencyMs;
            $status = $failure->status ?? $status;
            $bytes = $failure->bytes ?? $bytes;
            $limitBytes = $failure->limitBytes;
            $page = $failure->page ?? $page;
            $pages = $failure->pages ?? $pages;
        }

        Log::warning('connector.scheduled_fetch.failed', [
            'workspace_id' => $spec->workspaceId, 'data_source_id' => $spec->dataSourceId, 'endpoint_id' => $spec->endpointId,
            'code' => $code->value, 'reason' => $reason, 'status' => $status, 'page' => $page,
        ]);

        return new EndpointFetchResult(false, null, $status, $latencyMs, $bytes, $code, $reason, $urlTemplate, $names, $limitBytes, $page, $pages, dataSourceId: $spec->dataSourceId);
    }
}

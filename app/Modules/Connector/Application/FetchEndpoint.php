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
use App\Modules\Connector\Contracts\FailureClass;
use App\Modules\Connector\Contracts\FetchResponse;
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

    /** The longest validator kept, and the characters it may hold (visible ASCII and the space inside a date). */
    private const VALIDATOR_MAX = 512;

    /** @return array{name: string, value: string}|null `If-None-Match` from the ETag, else `If-Modified-Since` from Last-Modified, verbatim */
    private function conditional(EndpointFetchSpec $spec): ?array
    {
        return match (true) {
            $spec->ifNoneMatch !== null && $spec->ifNoneMatch !== '' => ['name' => 'If-None-Match', 'value' => $spec->ifNoneMatch],
            $spec->ifModifiedSince !== null && $spec->ifModifiedSince !== '' => ['name' => 'If-Modified-Since', 'value' => $spec->ifModifiedSince],
            default => null,
        };
    }

    /** The one value of a validator header of the final response, or null when it is absent, repeated, too long or not visible ASCII. Opaque: never parsed. */
    private static function validator(FetchResponse $response, string $name): ?string
    {
        $values = $response->headers[$name] ?? [];

        if (count($values) !== 1 || strlen($values[0]) > self::VALIDATOR_MAX || preg_match('/\A[\x21-\x7E](?:[\x20-\x7E]*[\x21-\x7E])?\z/D', $values[0]) !== 1) {
            return null;
        }

        return $values[0];
    }

    public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
    {
        $status = $latencyMs = $bytes = $limitBytes = $page = $pages = $code = $reason = $class = $retryAfter = null;
        $post = false;
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

            $post = $endpoint->method === 'POST';

            if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'not_read_only';
                $class = FailureClass::Configuration;
            } elseif ($endpoint->requiresUserContext) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'user_context_required';
                $class = FailureClass::Configuration;
            } else {
                $values = $this->renderer->values($endpoint, $spec->values);
                // Story 2.15: the stored validator goes out as a conditional header (an ETag wins), for an unpaged Data Source only: a paged
                // call merges pages, so no one validator speaks for it.
                $conditional = $source->pagination->enabled() ? null : $this->conditional($spec);
                $request = $this->renderer->request(
                    $spec->workspaceId, $source, $endpoint, $values, $this->vault->status($spec->workspaceId, $source->id), $spec->runId,
                    $conditional === null ? [] : [$conditional],
                );
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

                $paged = $request->pagination->enabled();

                if ($response->status === 304) {
                    if ($conditional === null) {
                        // Nothing conditional was sent, so a 304 is not an answer to anything: the caller starts over with a full fetch.
                        $code = ConnectionTestCode::FetchFailed;
                        $reason = EndpointFetchResult::NOT_MODIFIED_WITHOUT_PAYLOAD;
                        $class = FailureClass::Data;
                    } else {
                        return new EndpointFetchResult(
                            true, null, $status, $latencyMs, $bytes, null, null, $urlTemplate, $names, dataSourceId: $source->id, notModified: true,
                            etag: self::validator($response, 'etag'), lastModified: self::validator($response, 'last-modified'),
                        );
                    }
                } elseif (! $response->successful()) {
                    $code = ConnectionTestCode::FetchFailed;
                    $reason = 'http_'.$status;
                    // Story 2.17: a 429 or 503 may say when to come back; the number is all that is kept of the header.
                    $retryAfter = $status === 429 || $status === 503 ? RetryAfter::seconds($response->headers, new \DateTimeImmutable) : null;
                    $class = $this->ladder->classifyStatus($status, $retryAfter, $post);
                } else {
                    // A 2xx answer must be JSON that parses, losslessly; the value is dropped, the exact text is what is kept.
                    $response->assertJson();

                    return new EndpointFetchResult(
                        true, $response->body, $status, $latencyMs, $bytes, null, null, $urlTemplate, $names, page: $page, pages: $pages, dataSourceId: $source->id,
                        etag: $paged ? null : self::validator($response, 'etag'), lastModified: $paged ? null : self::validator($response, 'last-modified'),
                    );
                }
            }
        } catch (InvalidDataSource) {
            // The target's values no longer fit the revision they were registered for.
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'values_invalid';
            $class = FailureClass::Configuration;
        } catch (DataSourceNotFound|EndpointNotFound) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'endpoint_gone';
            $class = FailureClass::Configuration;
        } catch (Throwable $e) {
            $failure = $this->ladder->classify($e, $began, ['workspace_id' => $spec->workspaceId, 'endpoint_id' => $spec->endpointId], 'connector.scheduled_fetch', $post);
            $code = $failure->code;
            $reason = $failure->reason;
            $class = $failure->class;
            $latencyMs = $failure->latencyMs ?? $latencyMs;
            $status = $failure->status ?? $status;
            $bytes = $failure->bytes ?? $bytes;
            $limitBytes = $failure->limitBytes;
            $page = $failure->page ?? $page;
            $pages = $failure->pages ?? $pages;
        }

        Log::warning('connector.scheduled_fetch.failed', [
            'workspace_id' => $spec->workspaceId, 'data_source_id' => $spec->dataSourceId, 'endpoint_id' => $spec->endpointId,
            'code' => $code->value, 'reason' => $reason, 'class' => $class?->value, 'status' => $status, 'page' => $page,
        ]);

        return new EndpointFetchResult(false, null, $status, $latencyMs, $bytes, $code, $reason, $urlTemplate, $names, $limitBytes, $page, $pages, dataSourceId: $spec->dataSourceId, failureClass: $class, retryAfterSeconds: $retryAfter);
    }
}

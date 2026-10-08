<?php

namespace App\Modules\Connector\Application;

use App\Modules\Access\Contracts\AttributesUnavailable;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleBlobUnavailable;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\UserContextUnresolved;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;
use App\Support\Observability\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `sample_fetch` Operation (Story 2.10), run by `worker-connector` on queue `fetch-interactive` in the requester's
 * Workspace: it renders the Endpoint's current revision with the Admin's test values ({@see RenderEndpointRequest}) and sends
 * it through the {@see FetchTransport} and so through the guard. It reuses the error ladder of the connection test (host not
 * allowlisted, blocked address, not JSON, response too large, auth failed, fetch failed).
 *
 * On success the body is validated as JSON losslessly (Story 2.6) and handed to the requester as an encrypted, short-lived
 * Valkey blob ({@see SampleBlobStore}); it is never written to PostgreSQL, the Operation, `sync_runs`, a log or the audit. A
 * blob that cannot be sealed fails the test (`fetch-failed`, reason `blob_unavailable`) and nothing is stored. An Endpoint
 * whose revision moved before the request was made, or while it ran, ends the Operation as `stale` with nothing stored.
 *
 * A Fetch as user (Story 2.13) runs through {@see self::execute()} with a binder: the member's values are resolved here, on the server,
 * before the request is rendered, and the summary then also carries `missing` (the attribute key ids without a value, never a value).
 *
 * Every attempt is one `sync_runs` row of kind `sample_fetch` (the template, never a value). The summary is
 * `{ok, status, latency_ms, code, reason, size_bytes, limit_bytes, host, request_id, endpoint_revision, page, pages}`; for a paged Data
 * Source (Story 2.11) `page` is the page that failed or was reached and `pages` the pages fetched (both null otherwise), and
 * the bytes are the combined decompressed bytes of all pages.
 */
final class RunSampleFetch implements OperationHandler
{
    /** Set once the sample is kept for the requester; anything else leaves {@see self::cleanup()} to drop the blob. */
    private bool $keep = false;

    public function __construct(
        private readonly FetchTransport $transport,
        private readonly SecretVault $vault,
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RenderEndpointRequest $renderer,
        private readonly SampleBlobStore $blobs,
        private readonly RecordSyncRun $runs,
        private readonly RequestContext $context,
        private readonly EndpointFetchLadder $ladder,
    ) {}

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        return $this->execute($operation, $input, null);
    }

    /**
     * The whole run. `$bind` is set by {@see RunFetchAsUser}: it resolves the values bound to the member on the server (from the
     * Endpoint and the values the Admin gave) and returns every value to render, or throws {@see UserContextUnresolved} before any
     * request is made. Without it, an Endpoint that requires user context is never sent (the start refuses it; this is the backstop).
     * The resolved values stay in this method's memory: not in the summary, a log, `sync_runs` or the blob.
     *
     * @param  array<string, mixed>  $input
     * @param  (callable(Operation, Endpoint, array<string, string>): array<string, string>)|null  $bind
     */
    public function execute(Operation $operation, array $input, ?callable $bind): OperationOutcome
    {
        $startedAt = CarbonImmutable::now()->utc();
        $requestId = $operation->requestId ?? $this->context->requestId();
        $dataSourceId = is_string($input['data_source_id'] ?? null) ? $input['data_source_id'] : null;
        $endpointId = $operation->subjectId;
        $tested = $operation->subjectRevision;
        $values = [];

        foreach (is_array($input['values'] ?? null) ? $input['values'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }

        $status = $latencyMs = $bytes = $sizeBytes = $limitBytes = $code = $reason = null;
        // A paged Data Source (Story 2.11): the page that failed or was reached, and how many pages were fetched.
        $page = $pages = null;
        $host = '';
        $urlTemplate = '';
        $stale = null;
        $missing = null;
        $runKind = $bind === null ? SampleFetches::RUN_KIND : FetchesAsUser::RUN_KIND;
        $began = hrtime(true);

        try {
            if ($dataSourceId === null || $endpointId === null || $tested === null) {
                throw new EndpointNotFound;
            }

            $source = $this->sources->find($operation->workspaceId, $dataSourceId);
            $endpoint = $this->endpoints->find($operation->workspaceId, $dataSourceId, $endpointId);
            $host = $source->host;
            $urlTemplate = rtrim($source->baseUrl, '/').$endpoint->pathTemplate;

            if ($endpoint->revision !== $tested) {
                // The Endpoint changed before the request was made: it would test a revision nobody asked for. Nothing is sent.
                return $this->staleOutcome($operation, $requestId, $host, $endpoint->revision);
            }

            if ($endpoint->method === 'POST' && ! $endpoint->readOnlyQuery) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'not_read_only';
            } elseif ($bind === null && $endpoint->requiresUserContext) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'user_context_required';
            } else {
                if ($bind !== null) {
                    $values = $bind($operation, $endpoint, $values);
                }

                $request = $this->renderer->request($operation->workspaceId, $source, $endpoint, $values, $this->vault->status($operation->workspaceId, $source->id), $operation->id);
                $urlTemplate = $request->urlTemplate;
                $began = hrtime(true);
                $response = $this->transport->fetch($request);

                if ($request->pagination->enabled()) {
                    $page = $response->page;
                    $pages = $response->pages;
                }

                $status = $response->status;
                $latencyMs = $response->latencyMs;
                $bytes = $sizeBytes = $response->bytes;

                // The Endpoint may have been revised while the request ran: whatever came back is for a superseded revision.
                $current = $this->endpoints->find($operation->workspaceId, $dataSourceId, $endpointId);

                if ($current->revision !== $tested) {
                    $stale = $current;
                } elseif (! $response->successful()) {
                    $code = ConnectionTestCode::FetchFailed;
                    $reason = 'http_'.$status;
                } else {
                    // A 2xx answer must be JSON that parses, losslessly; the decoded value is dropped, the text is what is kept.
                    $response->assertJson();
                    $this->blobs->put(
                        $operation->workspaceId, $operation->id, $operation->requesterMembershipId, $endpoint->id, $tested,
                        $response->body, $this->lifetime($operation),
                    );

                    // Revised between the check and the store: drop what was just kept.
                    $after = $this->endpoints->find($operation->workspaceId, $dataSourceId, $endpointId);

                    if ($after->revision !== $tested) {
                        $stale = $after;
                    }
                }
            }
        } catch (AttributesUnavailable) {
            // A member's attribute cannot be opened (the `data` key is unusable): an operator problem, nothing was sent.
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'attributes_unavailable';
        } catch (UserContextUnresolved $e) {
            // Fail closed before any request: only attribute key ids and binding kinds are named, never a value.
            $code = ConnectionTestCode::ContextMissing;
            $reason = $e->reason;
            $missing = implode(',', $e->missing);
        } catch (InvalidDataSource) {
            // The values no longer fit the revision they were checked against.
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'values_invalid';
        } catch (DataSourceNotFound|EndpointNotFound) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'endpoint_gone';
        } catch (SampleBlobUnavailable $e) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'blob_unavailable';
            Log::error('connector.sample_fetch.blob_unavailable', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'reason' => $e->reason]);
        } catch (Throwable $e) {
            // The guard, transport, credential, limit and JSON failures of the shared ladder; an unknown one is `fetch-failed` (`error`).
            $failure = $this->ladder->classify($e, $began, ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id]);
            $code = $failure->code;
            $reason = $failure->reason;
            $latencyMs = $failure->latencyMs ?? $latencyMs;
            $status = $failure->status ?? $status;
            $bytes = $failure->bytes ?? $bytes;
            $sizeBytes = $failure->bytes ?? $sizeBytes;
            $limitBytes = $failure->limitBytes ?? $limitBytes;
            $page = $failure->page ?? $page;
            $pages = $failure->pages ?? $pages;
        }

        $ok = $code === null && $stale === null;

        try {
            // Its own savepoint: a failing history write must not discard the real result of the test.
            DB::transaction(fn () => $this->runs->record(
                $operation->workspaceId, $dataSourceId, $runKind, $urlTemplate, $ok,
                $status, $latencyMs, $bytes, $stale !== null ? 'stale' : $code?->value, $requestId, $startedAt,
            ));
        } catch (Throwable $e) {
            Log::error('connector.sample_fetch.sync_run_failed', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'exception' => $e::class]);
        }

        if ($stale !== null) {
            return $this->staleOutcome($operation, $requestId, $host, $stale->revision, $status, $latencyMs);
        }

        if (! $ok) {
            // Operator detail: identifiers and reason codes only, never an address, a header, a value or a body.
            Log::warning('connector.sample_fetch.failed', [
                'workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'data_source_id' => $dataSourceId, 'endpoint_id' => $endpointId,
                'code' => $code?->value, 'reason' => $reason, 'status' => $status, 'host' => $host, 'page' => $page,
            ]);
        }

        $this->keep = $ok;

        return new OperationOutcome($ok, [...($bind === null ? [] : ['missing' => $this->clip($missing)]),
            'ok' => $ok,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'code' => $code?->value,
            'reason' => $reason,
            'size_bytes' => $sizeBytes,
            'limit_bytes' => $limitBytes,
            'host' => substr($host, 0, 160),
            'request_id' => $requestId === null ? null : substr($requestId, 0, 64),
            'endpoint_revision' => $tested,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Whatever the outcome, a blob is dropped unless this run kept it for the requester: a failed, stale or crashed run leaves none (idempotent). */
    public function cleanup(Operation $operation): void
    {
        if (! $this->keep) {
            $this->blobs->forget($operation->workspaceId, $operation->id);
        }
    }

    /** The comma-separated key ids cut at a comma, never mid-id; null when there are none. */
    private function clip(?string $missing): ?string
    {
        if ($missing === null || $missing === '') {
            return null;
        }

        $kept = '';

        foreach (explode(',', $missing) as $id) {
            $next = $kept === '' ? $id : $kept.','.$id;

            if (strlen($next) > 150) {
                break;
            }

            $kept = $next;
        }

        return $kept === '' ? null : $kept;
    }

    private function staleOutcome(Operation $operation, ?string $requestId, string $host, int $current, ?int $status = null, ?int $latencyMs = null): OperationOutcome
    {
        $this->blobs->forget($operation->workspaceId, $operation->id);

        return OperationOutcome::stale([
            'ok' => false,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'code' => null,
            'reason' => 'stale',
            'size_bytes' => null,
            'limit_bytes' => null,
            'host' => substr($host, 0, 160),
            'request_id' => $requestId === null ? null : substr($requestId, 0, 64),
            'endpoint_revision' => $current,
            'page' => null,
            'pages' => null,
        ]);
    }

    /** Seconds the Operation still lives: the blob never outlives it. */
    private function lifetime(Operation $operation): int
    {
        return max(1, CarbonImmutable::parse($operation->expiresAt)->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}

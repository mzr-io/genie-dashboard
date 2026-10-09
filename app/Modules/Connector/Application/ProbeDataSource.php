<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\InvalidDataSourceUrl;
use App\Modules\Connector\Contracts\KeyringMismatch;
use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\ProbeResult;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SourceProbe;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestFailed;
use App\Platform\Json\InvalidLimitSetting;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The health probe (Story 2.18): one GET to the Base URL, or to it plus the Data Source's `health_path`, with the default headers and
 * credentials of the saved Data Source, built by the same {@see ConnectionTestRequest} as the connection test and sent through
 * {@see FetchTransport} (the egress guard and the credential handling). Ok is a 2xx or a 304: no JSON is required and the body is dropped.
 * It never retries, takes no governor token and tells the breaker nothing. The `sync_runs` row (kind `health_probe`) holds the Base URL as
 * the template, never the health path, a header, a query or a secret, and the log line only identifiers, the code and the status.
 */
final class ProbeDataSource implements SourceProbe
{
    public function __construct(
        private readonly DataSources $sources,
        private readonly ConnectionTestRequest $requests,
        private readonly FetchTransport $transport,
        private readonly RecordSyncRun $runs,
        private readonly RequestContext $context,
        private readonly MetricEmitter $metrics,
    ) {}

    public function probe(string $workspaceId, string $dataSourceId): ProbeResult
    {
        $source = $this->sources->find($workspaceId, $dataSourceId);
        $startedAt = CarbonImmutable::now()->utc();
        $baseUrl = $source->baseUrl;

        $status = null;
        $latencyMs = null;
        $bytes = null;
        $code = null;
        $ok = false;
        $began = hrtime(true);

        try {
            $url = DataSourceUrl::parse($source->baseUrl);
            $request = $this->requests->build($workspaceId, null, [
                'auth_type' => $source->authType,
                'headers' => $source->headers,
                'api_key_name' => $source->apiKeyName,
                'api_key_placement' => $source->apiKeyPlacement,
                'timeout_seconds' => $source->timeoutSeconds,
                'max_response_bytes' => $source->maxResponseBytes,
                'oauth_token_url' => $source->oauthTokenUrl,
                'oauth_client_id' => $source->oauthClientId,
                'oauth_scope' => $source->oauthScope,
            ], $url, $source->id, $source->healthPath === null ? null : rtrim($url->baseUrl, '/').$source->healthPath);

            $began = hrtime(true);
            $response = $this->transport->fetch($request);
            $status = $response->status;
            $latencyMs = $response->latencyMs;
            $bytes = $response->bytes;
            $ok = $response->successful() || $status === 304;
            $code = $ok ? null : ConnectionTestCode::FetchFailed;
        } catch (ResponseLimitExceeded $e) {
            // The source answered with more than the size limit: it is reachable, and the body is dropped anyway.
            $status = $e->status;
            $bytes = $e->bytesRead;
            $latencyMs = $this->elapsed($began);
            $ok = $status !== null && $status >= 200 && $status < 300;
            $code = $ok ? null : ConnectionTestCode::ResponseTooLarge;
        } catch (InvalidDataSourceUrl) {
            // A stored Base URL that no longer parses: a failed probe, not an exception.
            $code = ConnectionTestCode::FetchFailed;
        } catch (AuthFailed) {
            $latencyMs = $this->elapsed($began);
            $code = ConnectionTestCode::AuthFailed;
        } catch (SsrfBlocked $e) {
            $code = ConnectionTestCode::forEgress($e->reason);
        } catch (TokenRequestFailed|EgressTransportFailed) {
            $latencyMs = $this->elapsed($began);
            $code = ConnectionTestCode::FetchFailed;
        } catch (KeyringUnavailable|KeyringMismatch|SecretMissing|SecretRefused|InvalidLimitSetting) {
            $code = ConnectionTestCode::FetchFailed;
        } catch (Throwable $e) {
            $code = ConnectionTestCode::FetchFailed;
            Log::error('connector.health_probe.error', ['workspace_id' => $workspaceId, 'data_source_id' => $source->id, 'exception' => $e::class]);
        }

        try {
            // Its own savepoint: a failing history write must not discard the result of the probe.
            DB::transaction(fn () => $this->runs->record(
                $workspaceId, $source->id, SourceProbe::KIND, $baseUrl, $ok, $status, $latencyMs, $bytes, $code?->value,
                $this->context->requestId(), $startedAt,
            ));
        } catch (Throwable $e) {
            Log::error('connector.health_probe.sync_run_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }

        $this->metrics->increment('dashflow.connector.health_probe', ['workspace_id' => $workspaceId]);

        if (! $ok) {
            Log::warning('connector.health_probe.failed', ['workspace_id' => $workspaceId, 'data_source_id' => $source->id, 'code' => $code?->value, 'status' => $status]);
        }

        return new ProbeResult($ok, $status, $code?->value);
    }

    private function elapsed(int $began): int
    {
        return (int) round((hrtime(true) - $began) / 1_000_000);
    }
}

<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\ConnectionTests;
use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\KeyringMismatch;
use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestFailed;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;
use App\Support\Observability\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `connection_test` Operation (Story 2.5), run by `worker-connector` on queue `fetch-interactive` in the requester's
 * Workspace: one GET to the Base URL with the form's default headers and credentials through the {@see FetchTransport}.
 * Success is HTTP 2xx with a JSON body that parses (Story 2.6); the decoded value is dropped, never stored. Every outcome is one `sync_runs` row and a small summary
 * `{ok, status, latency_ms, code, reason, host, request_id}`. An OAuth2 source gets its token first (Story 2.7), recorded as its own
 * `oauth_token` run; a refusal of the credentials is `auth-failed`.
 *
 * A failure collapses to one of {@see ConnectionTestCode}'s user codes; a body that is not JSON and one over the size limit are
 * never retried. A too-large answer puts the bytes read and the limit in the summary as `size_bytes` and `limit_bytes`. The finer `reason` (a status, a timeout, a key
 * problem) is in the summary for the requester and in the operator log, and never includes a resolved address.
 */
final class RunConnectionTest implements OperationHandler
{
    /** cURL errors that are a TLS or certificate problem, for the `tls` reason. */
    public const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private readonly FetchTransport $transport,
        private readonly SecretVault $vault,
        private readonly RecordSyncRun $runs,
        private readonly RequestContext $context,
    ) {}

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        $startedAt = CarbonImmutable::now()->utc();
        $requestId = $operation->requestId ?? $this->context->requestId();
        $url = DataSourceUrl::parse($input['base_url'] ?? null);
        $dataSourceId = is_string($input['data_source_id'] ?? null) ? $input['data_source_id'] : null;

        $status = null;
        $latencyMs = null;
        $bytes = null;
        $sizeBytes = null;
        $limitBytes = null;
        $code = null;
        $reason = null;
        $urlTemplate = $url->baseUrl;
        $began = hrtime(true);

        try {
            $request = $this->request($operation, $input, $url, $dataSourceId);
            $urlTemplate = $request->urlTemplate;
            $began = hrtime(true);
            $response = $this->transport->fetch($request);

            $status = $response->status;
            $latencyMs = $response->latencyMs;
            $bytes = $response->bytes;

            if (! $response->successful()) {
                $code = ConnectionTestCode::FetchFailed;
                $reason = 'http_'.$status;
            } else {
                // Any 2xx answer must be JSON that parses; the decoded value is dropped, nothing is stored.
                $response->assertJson();
            }
        } catch (InvalidLimitSetting $e) {
            // A configured but malformed limit: fail closed, name the setting (never its value), once.
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'misconfigured';
            Log::error('connector.connection_test.limit_setting_invalid', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'setting' => $e->setting]);
        } catch (AuthFailed $e) {
            // The token endpoint refused the credentials, or the API refused a fresh token: never retried (Story 2.7).
            $latencyMs = $this->elapsed($began);
            $code = ConnectionTestCode::AuthFailed;
            $reason = $e->code()->value.':'.$e->reason;
        } catch (TokenRequestFailed $e) {
            $latencyMs = $this->elapsed($began);
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'oauth_'.$e->reason;
        } catch (NotJsonResponse $e) {
            $code = ConnectionTestCode::NotJson;
            $reason = $e->code()->value.':'.$e->reason;
        } catch (ResponseLimitExceeded $e) {
            $latencyMs = $this->elapsed($began);
            $status = $e->status;
            $bytes = $e->bytesRead;
            $sizeBytes = $e->bytesRead;
            $limitBytes = $e->limit;
            $code = ConnectionTestCode::ResponseTooLarge;
            $reason = $e->code()->value;
        } catch (SsrfBlocked $e) {
            $code = ConnectionTestCode::forEgress($e->reason);
            $reason = $e->reason->value;
        } catch (EgressTransportFailed $e) {
            $latencyMs = $this->elapsed($began);
            $code = ConnectionTestCode::FetchFailed;
            $reason = match (true) {
                $e->errno === 28 => 'timeout',
                in_array($e->errno, self::TLS_ERRORS, true) => 'tls',
                default => 'transport',
            };
        } catch (KeyringUnavailable) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'keyring_unavailable';
        } catch (KeyringMismatch) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'keyring_mismatch';
        } catch (SecretMissing) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'secret_missing';
        } catch (SecretRefused) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'secret_refused';
        } catch (Throwable $e) {
            $code = ConnectionTestCode::FetchFailed;
            $reason = 'error';
            Log::error('connector.connection_test.error', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'exception' => $e::class]);
        }

        $ok = $code === null;

        try {
            // Its own savepoint: a failing history write must not discard the real result of the test.
            DB::transaction(fn () => $this->runs->record(
                $operation->workspaceId, $dataSourceId, ConnectionTests::KIND, $urlTemplate, $ok,
                $status, $latencyMs, $bytes, $code?->value, $requestId, $startedAt,
            ));
        } catch (Throwable $e) {
            Log::error('connector.connection_test.sync_run_failed', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'exception' => $e::class]);
        }

        if (! $ok) {
            // Operator detail: identifiers and reason codes only, never an address, a header or a secret.
            Log::warning('connector.connection_test.failed', [
                'workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'data_source_id' => $dataSourceId,
                'code' => $code->value, 'reason' => $reason, 'status' => $status, 'host' => $url->host,
            ]);
        }

        return new OperationOutcome($ok, [
            'ok' => $ok,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'code' => $code?->value,
            'reason' => $reason,
            'size_bytes' => $sizeBytes,
            'limit_bytes' => $limitBytes,
            'host' => substr($url->host, 0, 160),
            'request_id' => $requestId === null ? null : substr($requestId, 0, 64),
        ]);
    }

    /** Removes the transient secrets of this Operation, whatever the outcome. */
    public function cleanup(Operation $operation): void
    {
        DB::selectOne('select connector_remove_operation_secrets(?::uuid) as removed', [$operation->id]);
    }

    /**
     * The fetch request for the form the Operation was started with: refs only for the credentials the form uses, a transient
     * row (this Operation's) before a stored one (the saved Data Source's). Expired transient rows are not listed.
     *
     * @param  array<string, mixed>  $input
     */
    private function request(Operation $operation, array $input, DataSourceUrl $url, ?string $dataSourceId): FetchRequest
    {
        $authType = is_string($input['auth_type'] ?? null) ? $input['auth_type'] : 'none';
        $headers = [];

        foreach (is_array($input['headers'] ?? null) ? $input['headers'] : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null)) {
                $headers[] = ($header['secret'] ?? false) === true
                    ? ['name' => $header['name'], 'value' => '', 'secret' => true]
                    : ['name' => $header['name'], 'value' => is_string($header['value'] ?? null) ? $header['value'] : ''];
            }
        }

        $used = SecretSlots::used($authType, $headers);
        $refs = [];

        foreach (DB::select(
            'select id, slot from secrets where workspace_id = ? and ephemeral and operation_id = ? and expires_at > now()',
            [$operation->workspaceId, $operation->id],
        ) as $row) {
            /** @var object{id: string, slot: string} $row */
            if (isset($used[$row->slot])) {
                $refs[$row->slot] = new SecretRef(strtolower($row->id), $row->slot, operationId: $operation->id);
            }
        }

        if ($dataSourceId !== null) {
            foreach ($this->vault->status($operation->workspaceId, $dataSourceId) as $slot => $status) {
                if (! isset($refs[$slot]) && isset($used[$slot]) && $status->id !== null) {
                    $refs[$slot] = new SecretRef($status->id, $slot, secretVersion: $status->secretVersion ?? 1);
                }
            }
        }

        $placement = is_string($input['api_key_placement'] ?? null) ? $input['api_key_placement'] : null;
        $client = $refs[SecretSlots::OAUTH_CLIENT_SECRET] ?? null;

        return new FetchRequest(
            $operation->workspaceId, $dataSourceId, null, $url->baseUrl, [], CredentialScheme::fromAuth($authType, $placement), array_values($refs),
            $headers, is_string($input['api_key_name'] ?? null) ? $input['api_key_name'] : null, $placement,
            is_int($input['timeout_seconds'] ?? null) ? $input['timeout_seconds'] : null,
            maxResponseBytes: is_int($input['max_response_bytes'] ?? null) ? $input['max_response_bytes'] : null,
            oauthTokenUrl: is_string($input['oauth_token_url'] ?? null) ? $input['oauth_token_url'] : null,
            oauthClientId: is_string($input['oauth_client_id'] ?? null) ? $input['oauth_client_id'] : null,
            oauthScope: is_string($input['oauth_scope'] ?? null) ? $input['oauth_scope'] : null,
            secretVersion: $client?->secretVersion,
        );
    }

    private function elapsed(int $began): int
    {
        return (int) round((hrtime(true) - $began) / 1_000_000);
    }
}

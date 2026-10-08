<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\ConnectionTests;
use App\Modules\Connector\Contracts\ConnectionTestThrottled;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretStatus;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Infrastructure\ConnectionTestSettings;
use App\Modules\Connector\Infrastructure\DataSourceSettings;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a connection test (Story 2.5). In order, before anything is written: the form must be testable (every credential
 * it needs has a value, typed or stored; https where required), then the rate limits per membership and per Workspace are
 * checked, and only then is the Operation enqueued. The values typed into secret fields are sealed through the vault into
 * transient `secrets` rows owned by the Operation (never into `data_sources`), in the same transaction as the Operation: a
 * rollback leaves neither. The queued job carries the non-secret form fields; `worker-connector` resolves the secrets itself.
 */
final class StartConnectionTest implements ConnectionTests
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Operations $operations,
        private readonly DataSources $sources,
        private readonly SecretVault $vault,
        private readonly DataSourceSettings $settings,
        private readonly ConnectionTestSettings $limits,
        private readonly RateLimiter $limiter,
    ) {}

    public function start(DataSourceActor $actor, #[\SensitiveParameter] DataSourceInput $input, ?string $dataSourceId = null): Operation
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $input, $dataSourceId): Operation {
            $source = $dataSourceId === null ? null : $this->sources->find($actor->workspaceId, $dataSourceId);

            $this->assertTestable($input, $source === null ? [] : $source->secrets);
            $this->hitLimits($actor);

            $operation = $this->operations->enqueue(
                $actor->workspaceId,
                self::KIND,
                $actor->membershipId,
                $source === null ? 'data_source_draft' : 'data_source',
                $source?->id,
                $source?->revision,
                [
                    'base_url' => $input->url->baseUrl,
                    'auth_type' => $input->authType,
                    'api_key_name' => $input->apiKeyName,
                    'api_key_placement' => $input->apiKeyPlacement,
                    'headers' => $input->headers,
                    'timeout_seconds' => $input->timeoutSeconds,
                    'max_response_bytes' => $input->maxResponseBytes,
                    'data_source_id' => $source?->id,
                ],
            );

            $this->stage($actor->workspaceId, $operation, $input);

            return $operation;
        });
    }

    /**
     * The form is refused (422) when https is required and the URL is http, or when a credential it needs has no value: not
     * typed now and not stored. Nothing here contacts a host: the allowlist is the guard's to enforce, and audit, at egress.
     *
     * @param  array<string, SecretStatus>  $stored
     */
    private function assertTestable(#[\SensitiveParameter] DataSourceInput $input, array $stored): void
    {
        $errors = [];
        $reasons = [];

        if ($input->url->scheme === 'http' && $this->settings->requireHttps()) {
            $errors['base_url'] = ['This workspace requires https. Use an https:// base URL.'];
            $reasons['base_url'] = 'https-required';
        }

        foreach (SecretSlots::used($input->authType, $input->headers) as $slot => $field) {
            if (($input->secretValues[$slot] ?? '') === '' && ! isset($stored[$slot])) {
                $errors[$field] = ['Enter a value.'];
                $reasons[$field] = 'secret-required';
            }
        }

        if ($errors !== []) {
            throw new InvalidDataSource($errors, $reasons);
        }
    }

    /**
     * Counts this request against every limit first (an atomic increment), then compares: concurrent requests cannot all
     * pass a check that none of them has counted yet. A request over a limit is refused with the wait.
     */
    private function hitLimits(DataSourceActor $actor): void
    {
        $window = $this->limits->window();
        $retry = 0;

        foreach ($this->scopes($actor) as [$key, $max]) {
            if ($window === null || $max === null) {
                continue;
            }

            if ($this->limiter->hit($key, $window) > $max) {
                $retry = max($retry, $this->limiter->availableIn($key));
            }
        }

        if ($retry > 0) {
            throw new ConnectionTestThrottled($retry);
        }
    }

    /** @return list<array{0: string, 1: int|null}> */
    private function scopes(DataSourceActor $actor): array
    {
        return [
            [TenantKey::cache($actor->workspaceId, 'connection-test:membership:'.strtolower($actor->membershipId)), $this->limits->membershipLimit()],
            [TenantKey::cache($actor->workspaceId, 'connection-test:workspace'), $this->limits->workspaceLimit()],
        ];
    }

    /** Seals each typed value to the platform key as a transient row of the Operation; it expires with the Operation. */
    private function stage(string $workspaceId, Operation $operation, #[\SensitiveParameter] DataSourceInput $input): void
    {
        $used = SecretSlots::used($input->authType, $input->headers);

        foreach ($input->secretValues as $slot => $value) {
            if (! isset($used[$slot]) || $value === '') {
                continue;
            }

            // The Operation is the owner this value is sealed for, so the worker opens it for that context only.
            $sealed = $this->vault->seal(new SecretContext($workspaceId, $operation->id, $slot), $value);

            DB::insert(
                "insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, ?, ?, 'cred', ?, ?, decode(?, 'base64'), now(), now())",
                [(string) Str::uuid7(), $workspaceId, $operation->id, $operation->expiresAt, $slot, $sealed->keyVersion, $sealed->keyRef, base64_encode($sealed->ciphertext)],
            );
        }
    }
}

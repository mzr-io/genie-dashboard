<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Registers the sync targets (Story 2.14). Connector cannot call Ingestion, so the targets are a read model fed by the outbox events
 * `connector.endpoint.created`, `connector.endpoint.revised` and `connector.data_source.updated` (IDs and revision numbers only). On each
 * event the consumer re-reads the current state through Connector's contracts and makes it true, idempotently:
 *
 *  - an Endpoint of shared context whose date-bound rows all have a test value gets one target for its current revision and the Data Source's
 *    current revision, keyed by the {@see FetchKeyResolver}; a target that exists is left as it is, a new one is due at once (when a refresh
 *    interval is set; the smallest of `refresh_intervals`, else it is registered and never scheduled);
 *  - every other target of the Endpoint is retired (`next_due_at` null, `retired_at` set; its payloads stay);
 *  - an Endpoint that needs user context has no representative target (a per-member target belongs to the subscription of Epic 3), and one
 *    with a date-bound row lacking a test value has no key and no target: the Admin sees why on the Endpoint.
 */
final class RegisterSyncTargets implements OutboxConsumer
{
    public const NAME = 'ingestion.register_sync_targets';

    private const TYPES = ['connector.endpoint.created', 'connector.endpoint.revised', 'connector.data_source.updated'];

    // The consumer is registered at boot on every role, so its collaborators are resolved when an event arrives, not when the application starts.

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return in_array($event->type, self::TYPES, true);
    }

    public function handle(OutboxEnvelope $event): void
    {
        $dataSourceId = $event->data['data_source_id'] ?? null;

        if (! is_string($dataSourceId) || ! Str::isUuid($dataSourceId)) {
            return;
        }

        try {
            $source = app(DataSources::class)->find($event->workspaceId, $dataSourceId);

            if ($event->type === 'connector.data_source.updated') {
                $endpoints = app(Endpoints::class)->list($event->workspaceId, $dataSourceId, null)->rows;
            } else {
                $endpointId = $event->data['endpoint_id'] ?? null;
                $endpoints = is_string($endpointId) && Str::isUuid($endpointId) ? [app(Endpoints::class)->find($event->workspaceId, $dataSourceId, $endpointId)] : [];
            }
        } catch (DataSourceNotFound|EndpointNotFound) {
            return;
        }

        if ($event->type === 'connector.data_source.updated') {
            // The Admin's retention choice reaches every target of the Data Source, retired ones too (Story 2.16). It never touches a fetch key or a payload pointer.
            DB::update(
                'update sync_targets set retention_mode = ?, retention_days = ?::integer, updated_at = now() where workspace_id = ? and data_source_id = ? and (retention_mode is distinct from ? or retention_days is distinct from ?::integer)',
                [$source->retention->mode, $source->retention->days, $event->workspaceId, $source->id, $source->retention->mode, $source->retention->days],
            );
        }

        foreach ($endpoints as $endpoint) {
            try {
                $this->register($event->workspaceId, $source, $endpoint);
            } catch (InvalidArgumentException $e) {
                // A stored value that is not well formed for its type: this Endpoint gets no key and no target, and the rest of the batch goes on.
                Log::error('ingestion.register.endpoint_skipped', ['workspace_id' => $event->workspaceId, 'exception' => $e::class]);
            }
        }
    }

    private function register(string $workspaceId, DataSource $source, Endpoint $endpoint): void
    {
        $params = $this->params($endpoint);
        $key = null;

        if ($params !== null) {
            $result = app(FetchKeyResolver::class)->resolve(new FetchKeyInput($workspaceId, $endpoint->revisionId, $source->revision, $params));
            $key = $result->key;
        }

        if ($key !== null) {
            $interval = app(SyncSettings::class)->refreshInterval();
            $id = (string) Str::uuid7();

            DB::insert(
                'insert into sync_targets (id, workspace_id, fetch_key, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, params, sync_group_id, refresh_interval_seconds, next_due_at, retention_mode, retention_days, created_at, updated_at) '
                .'values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?::integer, case when ?::integer is null then null else now() end, ?, ?::integer, now(), now()) on conflict (workspace_id, fetch_key) do nothing',
                [
                    $id, $workspaceId, $key, $source->id, $endpoint->id, $endpoint->revisionId, $source->revision,
                    json_encode((object) $params, JSON_THROW_ON_ERROR), $id, $interval, $interval, $source->retention->mode, $source->retention->days,
                ],
            );

            // A target registered while no refresh interval was set is scheduled once one is: it becomes due at once.
            if ($interval !== null) {
                DB::update(
                    'update sync_targets set refresh_interval_seconds = ?, next_due_at = now(), updated_at = now() where workspace_id = ? and fetch_key = ? and retired_at is null and next_due_at is null',
                    [$interval, $workspaceId, $key],
                );
            }
        }

        // Every other target of the Endpoint belongs to an earlier revision (or to none): it stops being scheduled, and its payloads stay. A target
        // that a subscription made for the current revisions (another period, or user-bound data; Story 2.19) is not an earlier one and stays.
        DB::update(
            'update sync_targets set next_due_at = null, retired_at = now(), updated_at = now() where workspace_id = ? and endpoint_id = ? and retired_at is null and (?::text is null or fetch_key <> ?::text) '
            .'and (endpoint_revision_id <> ? or data_source_revision <> ?::integer)',
            [$workspaceId, $endpoint->id, $key, $key, $endpoint->revisionId, $source->revision],
        );
    }

    /**
     * The typed parameters of the target, or null when there can be no representative target: the Endpoint needs user context, or a
     * date-bound row has no test value. A fixed row keeps its stored value (a string); a date-bound row is the Admin's test date.
     *
     * @return array<string, array{t: string, v: string}>|null
     */
    private function params(Endpoint $endpoint): ?array
    {
        if ($endpoint->requiresUserContext || $endpoint->missingTestValues() !== []) {
            return null;
        }

        $params = [];

        foreach ($endpoint->params as $param) {
            $value = $this->value($param['binding'], $param['value'], $endpoint->testValues[$param['name']] ?? null);

            if ($value === null) {
                return null;
            }

            $params[$param['name']] = $value;
        }

        foreach ($endpoint->headers as $header) {
            $value = $this->value($header['binding'], $header['value'], $endpoint->testValues['header:'.$header['name']] ?? null);

            if ($value === null) {
                return null;
            }

            $params['header:'.$header['name']] = $value;
        }

        return $params;
    }

    /** @return array{t: string, v: string}|null */
    private function value(string $binding, ?string $fixed, ?string $test): ?array
    {
        if ($binding === 'fixed') {
            return $fixed === null || $fixed === '' ? null : ['t' => 'string', 'v' => $fixed];
        }

        if (Endpoint::needsTestValue($binding)) {
            return $test === null || $test === '' ? null : ['t' => 'date', 'v' => $test];
        }

        return null;
    }
}

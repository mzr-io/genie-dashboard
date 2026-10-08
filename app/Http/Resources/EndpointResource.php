<?php

namespace App\Http\Resources;

use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Ingestion\Contracts\SyncStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Endpoint with its current revision (Story 2.9). The path and bindings are Admin configuration, not secrets: a header
 * value is a bound value the Admin typed (credentials are never Endpoint headers). `revision` is what an edit sends back.
 *
 * @property Endpoint $resource
 */
final class EndpointResource extends JsonResource
{
    private ?SyncStatus $sync = null;

    /** The scheduled-fetch status of the Endpoint (Story 2.14), read through Ingestion's contract by the controller and passed in. */
    public function withSync(?SyncStatus $sync): self
    {
        $this->sync = $sync;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $endpoint = $this->resource;

        return [
            'endpoint_id' => $endpoint->id,
            'data_source_id' => $endpoint->dataSourceId,
            'method' => $endpoint->method,
            'path' => $endpoint->pathTemplate,
            'path_ast' => $endpoint->pathAst,
            'params' => $endpoint->params,
            'headers' => $endpoint->headers,
            'body_template' => $endpoint->bodyTemplate,
            'read_only_query' => $endpoint->readOnlyQuery,
            // Story 2.13: derived from the bindings; never a user's value (a user-bound row holds a kind and, at most, an attribute key id).
            'requires_user_context' => $endpoint->requiresUserContext,
            'scope_by_caller' => $endpoint->scopeByCaller,
            // Story 2.14: the Admin's test values for the date-bound rows, and where the scheduled fetch stands (never a value of a response).
            'test_values' => (object) $endpoint->testValues,
            'sync' => $this->syncState($endpoint),
            'revision' => $endpoint->revision,
            'created_at' => $endpoint->createdAt,
            'updated_at' => $endpoint->updatedAt,
        ];
    }

    /**
     * `succeeded` with the time of the last good response, `waiting` (no successful call yet), or `not_scheduled` with the reason: `user_context`
     * (a per-member target belongs to Epic 3), `test_values` (a date-bound row has no test value, so there is no key and no target; the rows are
     * named) or `no_interval` (no refresh interval is set).
     *
     * @return array{state: string, last_success_at: string|null, reason: string|null, missing_test_values: list<string>}
     */
    private function syncState(Endpoint $endpoint): array
    {
        $missing = $endpoint->missingTestValues();

        if ($endpoint->requiresUserContext) {
            return ['state' => SyncStatus::NOT_SCHEDULED, 'last_success_at' => null, 'reason' => 'user_context', 'missing_test_values' => []];
        }

        if ($missing !== []) {
            return ['state' => SyncStatus::NOT_SCHEDULED, 'last_success_at' => null, 'reason' => 'test_values', 'missing_test_values' => $missing];
        }

        $sync = $this->sync ?? new SyncStatus(SyncStatus::WAITING);

        return ['state' => $sync->state, 'last_success_at' => $sync->lastSuccessAt, 'reason' => $sync->reason, 'missing_test_values' => []];
    }
}

<?php

namespace App\Http\Resources;

use App\Modules\Connector\Contracts\Endpoint;
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
            'revision' => $endpoint->revision,
            'created_at' => $endpoint->createdAt,
            'updated_at' => $endpoint->updatedAt,
        ];
    }
}

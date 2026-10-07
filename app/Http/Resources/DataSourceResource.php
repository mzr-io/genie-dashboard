<?php

namespace App\Http\Resources;

use App\Modules\Connector\Contracts\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Data Source. Health, the last successful call and the Blocks using it are fields of the row so Stories 2.18, 2.14
 * and Epic 3 only fill them; until then they stand at "checking", null and 0. No credential is ever part of it.
 *
 * @property DataSource $resource
 */
final class DataSourceResource extends JsonResource
{
    /** @param  bool  $headerValues  false for the list: header names only, never the values the table does not show */
    public function __construct(DataSource $source, private readonly bool $headerValues = true)
    {
        parent::__construct($source);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = $this->resource;

        return [
            'data_source_id' => $source->id,
            'name' => $source->name,
            'base_url' => $source->baseUrl,
            'scheme' => $source->scheme,
            'host' => $source->host,
            'port' => $source->port,
            'auth_type' => $source->authType,
            'headers' => $this->headerValues ? $source->headers : array_map(fn (array $h): array => ['name' => $h['name']], $source->headers),
            'timeout_seconds' => $source->timeoutSeconds,
            'max_response_bytes' => $source->maxResponseBytes,
            'max_pages' => $source->maxPages,
            'live_capable' => $source->liveCapable,
            'revision' => $source->revision,
            'health' => DataSource::HEALTH_PENDING,
            'last_successful_call_at' => null,
            'blocks_using' => 0,
            'created_at' => $source->createdAt,
            'updated_at' => $source->updatedAt,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\SecretSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Data Source. A credential is never part of it: a secret slot or header is only `{configured, updated_at}`. Health, the last successful call and the Blocks using it are fields of the row so Stories 2.18, 2.14
 * and Epic 3 only fill them; until then they stand at "checking", null and 0.
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
            'api_key_name' => $source->apiKeyName,
            'api_key_placement' => $source->apiKeyPlacement,
            'oauth_token_url' => $source->oauthTokenUrl,
            'oauth_client_id' => $source->oauthClientId,
            'oauth_scope' => $source->oauthScope,
            'headers' => array_map(fn (array $h): array => isset($h['secret'])
                ? ['name' => $h['name'], 'secret' => true]
                : ($this->headerValues ? ['name' => $h['name'], 'value' => $h['value']] : ['name' => $h['name']]), $source->headers),
            // Each slot in use, set or not: only `{configured, updated_at}`. The list leaves them out.
            ...($this->headerValues ? ['secrets' => $this->secrets($source)] : []),
            'timeout_seconds' => $source->timeoutSeconds,
            'max_response_bytes' => $source->maxResponseBytes,
            'max_pages' => $source->maxPages,
            'live_capable' => $source->liveCapable,
            'pagination_style' => $source->pagination->style,
            'pagination_param' => $source->pagination->param,
            'pagination_size_param' => $source->pagination->sizeParam,
            'pagination_size' => $source->pagination->size,
            'pagination_records_path' => $source->pagination->recordsPath,
            'pagination_cursor_path' => $source->pagination->cursorPath,
            'revision' => $source->revision,
            // The soft lock's epoch (Story 2.8): public state, a form echoes it back with its lock token.
            'lock_epoch' => $source->lockEpoch,
            'health' => DataSource::HEALTH_PENDING,
            'last_successful_call_at' => null,
            'blocks_using' => 0,
            'created_at' => $source->createdAt,
            'updated_at' => $source->updatedAt,
        ];
    }

    /** @return array<string, array{configured: bool, updated_at: string|null}> */
    private function secrets(DataSource $source): array
    {
        $slots = SecretSlots::forAuth($source->authType);

        foreach ($source->headers as $header) {
            if (isset($header['secret'])) {
                $slots[] = SecretSlots::header($header['name']);
            }
        }

        $out = [];

        foreach ($slots as $slot) {
            $status = $source->secrets[$slot] ?? null;
            $out[$slot] = ['configured' => $status !== null, 'updated_at' => $status?->updatedAt];
        }

        return $out;
    }
}

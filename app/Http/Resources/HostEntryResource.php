<?php

namespace App\Http\Resources;

use App\Modules\Connector\Contracts\HostEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One allowlist entry. "Added by" is the member's display name, resolved when the page is built and never stored
 * with the entry (null when the member can no longer be resolved).
 *
 * @property HostEntry $resource
 */
final class HostEntryResource extends JsonResource
{
    public function __construct(HostEntry $entry, private readonly ?string $addedByName)
    {
        parent::__construct($entry);
    }

    /**
     * @return array<string, mixed> {entry_id, host, scheme, port, added_by, added_at}
     */
    public function toArray(Request $request): array
    {
        return [
            'entry_id' => $this->resource->id,
            'host' => $this->resource->host,
            'scheme' => $this->resource->scheme,
            'port' => $this->resource->port,
            'added_by' => $this->addedByName,
            'added_at' => $this->resource->createdAt,
        ];
    }
}

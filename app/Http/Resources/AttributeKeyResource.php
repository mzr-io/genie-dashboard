<?php

namespace App\Http\Resources;

use App\Modules\Access\Contracts\AttributeKeyRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One user attribute key: the immutable key id and value type, the editable label and the revision.
 *
 * @property AttributeKeyRow $resource
 */
final class AttributeKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed> {id, key_id, label, value_type, revision, created_at, updated_at}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key_id' => $this->resource->keyId,
            'label' => $this->resource->label,
            'value_type' => $this->resource->valueType,
            'revision' => $this->resource->revision,
            'created_at' => $this->resource->createdAt,
            'updated_at' => $this->resource->updatedAt,
        ];
    }
}

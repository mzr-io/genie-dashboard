<?php

namespace App\Http\Resources;

use App\Modules\Blocks\Contracts\Draft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Draft of a Block (Story 3.3). Never a sample or a mapping. The `config` (Story 3.4) is included only when asked for: the wizard's
 * single-Draft read and its saves carry it, the list does not.
 *
 * @property Draft $resource
 */
final class DraftResource extends JsonResource
{
    /** @param  string|null  $owner  the owner's display name when the caller resolved it (the list); null otherwise */
    public function __construct(Draft $draft, private readonly ?string $owner = null, private readonly bool $withConfig = false)
    {
        parent::__construct($draft);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $draft = $this->resource;

        $config = $this->withConfig
            ? ['config' => $draft->config === [] ? ['schema_version' => 1] : $draft->config, 'ui_state' => $draft->uiState === [] ? (object) [] : $draft->uiState]
            : [];

        return [
            'draft_id' => $draft->id,
            'block_id' => $draft->blockId,
            'name' => $draft->name,
            'type' => $draft->type,
            'category' => $draft->category,
            'category_id' => $draft->categoryId,
            'version' => $draft->version,
            'state' => 'draft',
            'revision' => $draft->revision,
            'lock_epoch' => $draft->lockEpoch,
            'step' => $draft->step,
            'owner_membership_id' => $draft->ownerMembershipId,
            'owner' => $this->owner,
            'created_at' => $draft->createdAt,
            'updated_at' => $draft->updatedAt,
        ] + $config;
    }
}

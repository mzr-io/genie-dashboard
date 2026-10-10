<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftFieldRoles;
use App\Modules\Blocks\Contracts\DraftRoles;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Mapping\Contracts\FieldRoles;

/**
 * Field roles of a Draft's sample (Story 3.11a): the Draft's stored sample structure and its `config.record_path` / `config.field_roles`
 * (the Admin's overrides, the single source of truth) through Mapping's inference. Writes nothing; nothing of the sample is in a refusal.
 */
final class ReadDraftFieldRoles implements DraftFieldRoles
{
    public function __construct(
        private readonly Drafts $drafts,
        private readonly SampleShapes $shapes,
        private readonly FieldRoles $roles,
    ) {}

    public function roles(DraftActor $actor, string $draftId): DraftRoles
    {
        $draft = $this->drafts->find($actor->workspaceId, $draftId);
        $found = $this->shapes->of($actor->workspaceId, $draft->id);

        if ($found === null) {
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        [$shape, $sampleRevision] = $found;

        $path = $draft->config['record_path'] ?? null;
        $overrides = is_array($draft->config['field_roles'] ?? null) ? array_filter($draft->config['field_roles'], is_string(...)) : [];

        return new DraftRoles($this->roles->infer($shape, is_string($path) ? $path : null, $overrides), $draft->revision, $sampleRevision);
    }
}

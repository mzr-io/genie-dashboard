<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PreviewThrottled;
use App\Modules\Mapping\Contracts\SettingNotConfigured;

/**
 * The live preview of a Draft (Story 3.10): the Draft's Query Plan run over the Draft's stored sample by the same engine production runs. It needs
 * only an existing Draft (`blocks.edit` gates the route; the edit lock is not taken and nothing is written), and it never puts the sample in a
 * shared cache: the decoded sample is kept in this process, per `(workspace, draft, sample_revision)`.
 */
interface DraftPreviews
{
    /**
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws DraftPublished the version is no longer a Draft
     * @throws PreviewRefused the Draft has no plan or no sample
     * @throws SettingNotConfigured a `pending_input` preview setting is unset (nothing run)
     * @throws PreviewThrottled over the per-membership limit
     * @throws InvalidPlan the stored plan is not one the engine runs
     * @throws GuardExceeded a guard was passed: no payload
     * @throws SampleUnavailable the stored sample cannot be opened
     */
    public function preview(DraftActor $actor, string $draftId): DraftPreview;
}

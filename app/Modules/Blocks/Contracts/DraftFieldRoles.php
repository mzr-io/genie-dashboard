<?php

namespace App\Modules\Blocks\Contracts;

/** The field roles and record-path candidates of a Draft's sample (Story 3.11a). Read-only: it writes nothing and takes no lock. */
interface DraftFieldRoles
{
    /**
     * @throws DraftNotFound the Draft does not exist, is discarded or is no longer a Draft
     * @throws PreviewRefused the Draft has no usable sample (`mapping.no_sample`)
     * @throws SampleUnavailable the stored sample cannot be opened
     */
    public function roles(DraftActor $actor, string $draftId): DraftRoles;
}

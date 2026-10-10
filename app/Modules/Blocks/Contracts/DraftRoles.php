<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\FieldRoleReport;

/** The field roles of a Draft's sample with the revisions they were read at (Story 3.13c). */
final readonly class DraftRoles
{
    public function __construct(public FieldRoleReport $report, public int $draftRevision, public int $sampleRevision) {}
}

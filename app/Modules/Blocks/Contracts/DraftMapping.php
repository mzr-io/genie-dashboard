<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\FieldRoleReport;

/**
 * The one consistent read of a Draft's mapping (Story 3.13c), taken in one pass: the checklist, the field roles, the stored plan and both revisions.
 * `sample` is `ok`, `expired` when the Draft has no live sample, or `invalid` when its stored sample no longer passes the limits; in the last two cases then `checklist`, `roles` and `sampleRevision` are null and only the stored
 * decisions (`slotMap`) and the stored plan are returned. `planStale` is true when a plan is stored and was not stamped with the current sample revision (an unstamped plan or an absent sample included).
 */
final readonly class DraftMapping
{
    public const SAMPLE_OK = 'ok';

    public const SAMPLE_EXPIRED = 'expired';

    /** A stored sample that no longer passes the limits. */
    public const SAMPLE_INVALID = 'invalid';

    /**
     * @param  array<string, mixed>  $slotMap
     * @param  array<string, mixed>|null  $queryPlan
     */
    public function __construct(
        public ?AutoMapResult $checklist,
        public ?FieldRoleReport $roles,
        public array $slotMap,
        public ?array $queryPlan,
        public int $draftRevision,
        public ?int $sampleRevision,
        public bool $planStale,
        public string $sample,
    ) {}

    /** The validator of a conditional GET: both revisions name the content. */
    public function etag(): string
    {
        return '"d'.$this->draftRevision.'-s'.($this->sampleRevision ?? 0).'"';
    }
}

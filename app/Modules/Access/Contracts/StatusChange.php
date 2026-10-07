<?php

namespace App\Modules\Access\Contracts;

/** A membership's status and revision (Story 1.24), after a change or as they stand now. */
final readonly class StatusChange
{
    /** The one definition of the membership statuses this story moves between; any other stored status counts as not active. */
    public const ACTIVE = 'active';

    public const DEACTIVATED = 'deactivated';

    /**
     * @param  string  $status  `active` or `deactivated`
     * @param  bool  $changed  false for a no-op (already in the target status): nothing was written and the revision is unchanged
     */
    public function __construct(
        public string $membershipId,
        public string $status,
        public int $revision,
        public bool $changed = true,
    ) {}
}

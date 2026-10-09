<?php

namespace App\Modules\Ingestion\Contracts;

/** The outcome of {@see Subscribe}: the target and the time the subscription stays hot until, or the reason there is none. Never a value of the user. */
final readonly class SubscribeResult
{
    /** The Endpoint's date-bound row has neither a resolved period nor an Admin test value. */
    public const PERIOD_MISSING = 'period_missing';

    /** A fixed row has no value, so no fetch key can be made. */
    public const PARAMS_UNRESOLVED = 'params_unresolved';

    /** The member already caused the most new per-user targets an hour allows (`budgets.max_new_cold_keys_per_membership_per_hour`). */
    public const BUDGET_LIMITED = 'budget.new_cold_keys';

    /** Story 2.20: the primary a comparison names is not a live sync target of the Workspace, is itself a comparison, or is the comparison itself. */
    public const PRIMARY_UNKNOWN = 'primary_unknown';

    /** Story 2.20: the comparison target already belongs to another group, or the primary already has another comparison. */
    public const GROUP_CONFLICT = 'group_conflict';

    private function __construct(
        public ?string $syncTargetId,
        /** ISO 8601, UTC; null while `sync.hot_window` is unset. */
        public ?string $hotUntil,
        public ?string $reason,
        public bool $budgetLimited,
    ) {}

    public static function subscribed(string $syncTargetId, ?string $hotUntil): self
    {
        return new self($syncTargetId, $hotUntil, null, false);
    }

    public static function refused(string $reason): self
    {
        return new self(null, null, $reason, $reason === self::BUDGET_LIMITED);
    }

    public function ok(): bool
    {
        return $this->syncTargetId !== null;
    }
}

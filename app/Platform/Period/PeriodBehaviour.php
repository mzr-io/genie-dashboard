<?php

namespace App\Platform\Period;

/** How a Block follows the period (FR-17): the values of a Block's `display.period`. */
enum PeriodBehaviour: string
{
    case FollowDashboard = 'follow_dashboard';
    case OwnSelector = 'own_selector';
    case NotDateFiltered = 'not_date_filtered';

    /** The behaviour a stored value names; a Block with none follows the dashboard (FR-17). */
    public static function of(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::FollowDashboard;
    }

    /** Whether a stored value names the Own selector: the only behaviour that has a Block Period Selector. */
    public static function hasSelector(?string $value): bool
    {
        return self::of($value) === self::OwnSelector;
    }
}

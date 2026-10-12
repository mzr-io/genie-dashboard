<?php

namespace App\Platform\Period;

/** How a Block follows the period (FR-17): the values of a Block's `display.period`. */
enum PeriodBehaviour: string
{
    case FollowDashboard = 'follow_dashboard';
    case OwnSelector = 'own_selector';
    case NotDateFiltered = 'not_date_filtered';
}

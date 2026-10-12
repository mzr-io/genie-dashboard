<?php

namespace App\Modules\Dashboards\Contracts;

/** The outcome of `DuplicateDashboard` (Story 4.16): the new dashboard and how many items were left out because the member can no longer use their Block. */
final readonly class DuplicateOutcome
{
    public function __construct(public DashboardSummary $dashboard, public int $omitted) {}
}

<?php

namespace App\Modules\Dashboards\Contracts;

/** One dashboard of a member as the switcher and My dashboards list it (Story 4.16): Overview first, then by creation. */
final readonly class DashboardSummary
{
    public function __construct(
        public string $dashboardId,
        public string $name,
        public bool $isOverview,
        public int $itemCount,
        public string $updatedAt,
    ) {}
}

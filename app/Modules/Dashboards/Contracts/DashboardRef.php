<?php

namespace App\Modules\Dashboards\Contracts;

/** A dashboard as an Inertia page carries it (Story 4.4, AD-20): its id and revision, nothing of its layout. */
final readonly class DashboardRef
{
    public function __construct(public string $dashboardId, public int $revision) {}
}

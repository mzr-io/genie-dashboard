<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** `DeleteDashboard` of an Overview (422, `msg:overview-delete`). */
final class OverviewUndeletable extends RuntimeException {}

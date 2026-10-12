<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** No such dashboard for this member: it does not exist, belongs to another member, or is in another Workspace. The three are indistinguishable (404). */
final class DashboardNotFound extends RuntimeException {}

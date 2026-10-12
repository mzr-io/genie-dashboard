<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** A dashboard name that is empty after trimming, longer than 120 characters or holds a control character (422, field `name`). */
final class InvalidDashboardName extends RuntimeException {}

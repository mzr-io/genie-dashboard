<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** A layout command named an item that is not on the dashboard, or one twice (422): nothing changed. */
final class InvalidLayout extends RuntimeException {}

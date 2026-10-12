<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** The item is not on this member's dashboard (404). */
final class ItemNotFound extends RuntimeException {}

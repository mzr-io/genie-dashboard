<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** The item is required by an Admin (Mandatory) and cannot be removed (403, `msg:locked`). */
final class ItemLocked extends RuntimeException {}

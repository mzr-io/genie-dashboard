<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** Another group of the Workspace already has this name (case-insensitive, trimmed): HTTP 422 with a field error. */
final class GroupNameTaken extends RuntimeException {}

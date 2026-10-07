<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The group is not in the Workspace (or the ID is not a group ID): HTTP 404. */
final class GroupNotFound extends RuntimeException {}

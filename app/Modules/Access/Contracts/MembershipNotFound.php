<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The member is not in the Workspace (or the ID is not a membership ID): HTTP 404. */
final class MembershipNotFound extends RuntimeException {}

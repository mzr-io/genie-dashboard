<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** Permissions were set on a member whose role is `user` (HTTP 422): a User holds none. */
final class PermissionsOnUserRole extends RuntimeException {}

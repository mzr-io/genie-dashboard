<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The change would leave the Workspace without an active holder of `users.manage` (`access.last_users_manage_holder`, HTTP 409). */
final class LastUsersManageHolder extends RuntimeException {}

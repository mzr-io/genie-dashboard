<?php

namespace App\Modules\Identity\Contracts;

use RuntimeException;

/** The invitation's inviter is no longer an active Admin of the Workspace (or `created_by` names no valid inviter): it must not be accepted. */
final class InviterGone extends RuntimeException {}

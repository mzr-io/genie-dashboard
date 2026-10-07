<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** No pending invitation with that ID in the Workspace (unknown, used, revoked, expired, or another Workspace's). */
final class InvitationNotFound extends RuntimeException {}

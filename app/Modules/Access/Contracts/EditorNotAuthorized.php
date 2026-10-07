<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The editor is not (any longer) an active Admin holding `users.manage` (`access.not_authorized`, HTTP 403), seen under the lock. */
final class EditorNotAuthorized extends RuntimeException {}

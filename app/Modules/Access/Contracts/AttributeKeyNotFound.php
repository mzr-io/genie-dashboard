<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The user attribute key is not in the Workspace: HTTP 404. */
final class AttributeKeyNotFound extends RuntimeException {}

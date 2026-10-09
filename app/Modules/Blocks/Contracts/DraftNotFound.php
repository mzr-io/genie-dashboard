<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** No such Draft in the Workspace, or it was discarded (row-level security hides other Workspaces): HTTP 404. */
final class DraftNotFound extends RuntimeException {}

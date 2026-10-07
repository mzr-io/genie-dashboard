<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The entry is not in the Workspace (or the ID is not an entry ID): HTTP 404. */
final class HostEntryNotFound extends RuntimeException {}

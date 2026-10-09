<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** No such Data Source in the Workspace (row-level security hides other Workspaces): HTTP 404. */
final class DataSourceNotFound extends RuntimeException {}

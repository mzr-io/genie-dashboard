<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The Workspace already allows this host and port (whatever the scheme): HTTP 422 with a field error. */
final class HostAlreadyAllowed extends RuntimeException {}

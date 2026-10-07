<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The editor targeted their own membership (`access.self_change_forbidden`, HTTP 403): nobody edits their own role or permissions. */
final class SelfChangeForbidden extends RuntimeException {}

<?php

namespace App\Modules\Identity\Application;

use RuntimeException;

/** A switch to a Workspace the person has no usable membership in (unknown, foreign, stale or deactivated). */
final class WorkspaceSwitchRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('workspace_forbidden');
    }
}

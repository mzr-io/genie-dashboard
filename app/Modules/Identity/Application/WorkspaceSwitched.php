<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SignInArea;

/** The outcome of a switch: the new active Workspace, the area now in force and whether the area dropped to User. */
final readonly class WorkspaceSwitched
{
    public function __construct(
        public string $workspaceId,
        public string $workspaceName,
        public SignInArea $area,
        public bool $downgraded,
    ) {}
}

<?php

namespace App\Platform\Tenancy;

/**
 * Job middleware for a WorkspaceScopedJob: re-enters the Workspace context before the job runs.
 */
trait RunsInWorkspace
{
    /**
     * @return array<int, callable>
     */
    public function middleware(): array
    {
        return [app(WorkspaceTransaction::class)->runJob(...)];
    }
}

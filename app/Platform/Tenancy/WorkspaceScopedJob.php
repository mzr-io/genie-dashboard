<?php

namespace App\Platform\Tenancy;

/**
 * A queued job that runs inside one Workspace.
 *
 * `workspaceId()` must return a property of the job, so it is part of the serialised command and
 * therefore covered by the job signature. Use the RunsInWorkspace trait for the middleware.
 */
interface WorkspaceScopedJob
{
    public function workspaceId(): string;

    /**
     * The IDs the job was given, by table: `['table' => ['id', ...]]`. They are re-read under the
     * Workspace's row-level security before the job runs; any ID that is not visible is a mismatch.
     *
     * @return array<string, list<string>>
     */
    public function referencedIds(): array;
}

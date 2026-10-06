<?php

namespace App\Platform\Tenancy;

use RuntimeException;

/** A job's IDs do not belong to its Workspace, or its Workspace ID is malformed. Carries IDs only, never data. */
final class WorkspaceMismatchException extends RuntimeException
{
    public const SECURITY_EVENT = 'security.tenancy.workspace_mismatch';

    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public readonly ?string $workspaceId,
        public readonly string $table,
        public readonly array $ids,
        public readonly string $reason,
    ) {
        parent::__construct('Job references data outside its Workspace ('.$reason.').');
    }
}

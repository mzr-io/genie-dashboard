<?php

namespace App\Modules\Access\Contracts;

/**
 * Registers something that can be limited to groups as an Access subject (Story 3.19, AR-3): a Block when it is published (a Template later). It
 * must run inside the caller's open Workspace transaction, so the subject exists exactly when the thing that makes it visible commits. A new subject
 * is visible to "All users" (`mode` `all`); no grant is keyed by a version. Registering again changes nothing (an access change is Epic 6's).
 */
interface RegisterAccessSubject
{
    public const BLOCK = 'block';

    public const TEMPLATE = 'template';

    /**
     * @param  string  $type  {@see self::BLOCK} or {@see self::TEMPLATE}
     * @return bool whether the subject was created by this call
     */
    public function register(string $workspaceId, string $type, string $subjectId): bool;
}

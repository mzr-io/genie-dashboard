<?php

namespace App\Modules\Dashboards\Contracts;

use RuntimeException;

/** A layout command's `expected_revision` is not the dashboard's revision (409): nothing changed, and the current revision is carried back. */
final class RevisionConflict extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('The dashboard has changed.');
    }
}

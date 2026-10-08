<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The key id (`key_id`) or the label (`label`) is already used in the Workspace: HTTP 422 with a field error. */
final class AttributeKeyTaken extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('attribute key taken');
    }
}

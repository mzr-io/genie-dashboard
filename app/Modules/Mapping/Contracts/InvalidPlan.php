<?php

namespace App\Modules\Mapping\Contracts;

use RuntimeException;

/** A Query Plan the engine refuses (an unknown `v`, a bad path or a slot the Block Type does not have), by field. */
final class InvalidPlan extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The query plan is invalid.');
    }
}

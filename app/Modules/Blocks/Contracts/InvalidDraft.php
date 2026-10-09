<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A Draft field the application refuses (an unknown Block Type, a Block that is not there): HTTP 422 with field errors. */
final class InvalidDraft extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The draft is invalid.');
    }
}

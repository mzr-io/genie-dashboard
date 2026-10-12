<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A Draft field the application refuses (an unknown Block Type, a Block that is not there): HTTP 422 with field errors. */
final class InvalidDraft extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  string|null  $errorCode  the owning module's error code when the failure has one (`blocks.invalid_config`); null: the generic 422
     */
    public function __construct(public readonly array $errors, public readonly ?string $errorCode = null)
    {
        parent::__construct('The draft is invalid.');
    }
}

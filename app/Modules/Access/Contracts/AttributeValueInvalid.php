<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** One or more values are refused (HTTP 422, nothing stored): `reasons` maps the key id to `undefined_key`, `empty`, `too_long` or `invalid`. */
final class AttributeValueInvalid extends RuntimeException
{
    /** @param  array<string, string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('attribute value invalid');
    }
}

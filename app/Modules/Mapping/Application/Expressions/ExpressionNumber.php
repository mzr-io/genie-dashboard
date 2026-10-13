<?php

namespace App\Modules\Mapping\Application\Expressions;

/** A number inside an expression: the digits, never a float. */
final readonly class ExpressionNumber
{
    public function __construct(public string $value) {}
}

<?php

namespace App\Modules\Mapping\Application\Expressions;

use RuntimeException;

/** A value the expression cannot read as what it is used as (text in arithmetic): the run makes the slots that read it unavailable, never 0. */
final class ExpressionTypeError extends RuntimeException {}

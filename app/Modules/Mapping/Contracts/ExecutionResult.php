<?php

namespace App\Modules\Mapping\Contracts;

/**
 * What an executor made of a plan: `slots` is every slot key of the Block Type (one the plan does not shape is null and `missing`), with its scalar (a decimal string, text or a boolean) or null, and `unavailable`
 * names why a null slot is null (`missing`, `not_single_value` or `wrong_type`).
 */
final readonly class ExecutionResult
{
    public const MISSING = 'missing';

    public const NOT_SINGLE_VALUE = 'not_single_value';

    public const WRONG_TYPE = 'wrong_type';

    /** A string a number slot accepts (after trimming): the executor and the auto-mapper's "treat as number" check share it. */
    public const DECIMAL = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    /**
     * @param  array<string, string|bool|null>  $slots
     * @param  array<string, string>  $unavailable
     */
    public function __construct(public array $slots, public array $unavailable) {}
}

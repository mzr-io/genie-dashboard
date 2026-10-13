<?php

namespace App\Modules\Mapping\Contracts;

/**
 * What an executor made of a plan: `slots` is every slot key of the Block Type (one the plan does not shape is null and `missing`), with its scalar (a decimal string, text or a boolean) or null, or for a `rows` slot (Story 5.8a) the list of the value of every row (a null among them where a row has none), and `unavailable`
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
     * @param  array<string, string|bool|list<string|bool|null>|null>  $slots
     * @param  array<string, string>  $unavailable
     * @param  array{input: int, steps: list<int>}|null  $rows  the rows before the transforms and after each of them in stage order; null while the plan has none
     * @param  array<string, list<string|bool|null>>  $columns  with transforms: each row-aligned alias's values in the order the rows are left in (a decimal as its digits, null for anything that is not a scalar)
     */
    public function __construct(public array $slots, public array $unavailable, public ?array $rows = null, public array $columns = []) {}
}

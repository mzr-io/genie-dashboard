<?php

namespace App\Modules\Mapping\Contracts;

/**
 * The result of running a plan for a Block Type: the Shaper's payload, the reason each unavailable slot is unavailable and, per slot, the scalar the
 * executor read (a decimal string, text, a boolean, or null) before shaping (Story 3.13c).
 */
final readonly class PlanResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $unavailable  slot key => `missing`, `not_single_value` or `wrong_type`
     * @param  array<string, string|bool|list<string|bool|null>|null>  $slots
     * @param  array{input: int, steps: list<int>}|null  $rows  the row counts of the transforms (Story 5.1), null while the plan has none
     * @param  array<string, list<string|bool|null>>  $columns  the rows the transforms left, by alias
     */
    public function __construct(public array $payload, public array $unavailable, public array $slots = [], public ?array $rows = null, public array $columns = []) {}
}

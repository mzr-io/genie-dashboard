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
     * @param  array<string, string|bool|null>  $slots
     */
    public function __construct(public array $payload, public array $unavailable, public array $slots = []) {}
}

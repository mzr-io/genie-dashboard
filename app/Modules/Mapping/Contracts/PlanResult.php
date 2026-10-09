<?php

namespace App\Modules\Mapping\Contracts;

/** The result of running a plan for a Block Type: the Shaper's payload and the reason each unavailable slot is unavailable. */
final readonly class PlanResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $unavailable  slot key => `missing`, `not_single_value` or `wrong_type`
     */
    public function __construct(public array $payload, public array $unavailable) {}
}

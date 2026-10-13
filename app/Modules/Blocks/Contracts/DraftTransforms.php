<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The transform steps a Draft stores after a write (Story 5.1): the validated `config.transforms` in the fixed stage order (empty when cleared), the
 * Draft's revision after the call, the plan the Draft now stores (null when nothing is mapped) and the steps the sample cannot satisfy.
 */
final readonly class DraftTransforms
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>|null  $queryPlan
     * @param  list<array{index: int, type: string, field: string|null, reason: string, function: string|null, field_type: string|null}>  $faults
     */
    public function __construct(public array $steps, public int $revision, public ?array $queryPlan = null, public array $faults = []) {}
}

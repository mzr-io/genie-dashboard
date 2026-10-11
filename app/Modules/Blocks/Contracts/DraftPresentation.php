<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The presentation rules a Draft stores after a write (Story 3.15a): the validated `config.presentation` (empty when cleared), the Draft's revision after the
 * call and the plan the Draft now stores (null when nothing is mapped).
 */
final readonly class DraftPresentation
{
    /**
     * @param  array<string, mixed>  $presentation
     * @param  array<string, mixed>|null  $queryPlan
     */
    public function __construct(public array $presentation, public int $revision, public ?array $queryPlan = null) {}
}

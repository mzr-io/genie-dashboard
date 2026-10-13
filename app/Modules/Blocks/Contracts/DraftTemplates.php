<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The Text Templates a Draft stores after a write (Story 5.4): `{slot key: {source, parts}}` (empty when cleared), the Draft's revision after the call
 * and the plan the Draft now stores (null when nothing is mapped).
 */
final readonly class DraftTemplates
{
    /**
     * @param  array<string, array<string, mixed>>  $templates
     * @param  array<string, mixed>|null  $queryPlan
     */
    public function __construct(public array $templates, public int $revision, public ?array $queryPlan = null) {}
}

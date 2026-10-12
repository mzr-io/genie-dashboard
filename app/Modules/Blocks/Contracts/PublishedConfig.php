<?php

namespace App\Modules\Blocks\Contracts;

/**
 * A published Block version's frozen configuration as Results reads it (Story 4.2): the Block Type and contract it pins, its Query Plan, its
 * presentation rules and the field snapshot (`config.fields`) the plan was made against. Immutable once published.
 */
final readonly class PublishedConfig
{
    /**
     * @param  array<string, mixed>  $queryPlan
     * @param  array<string, mixed>  $presentation
     * @param  list<array{alias: string, path: string, role: string|null, type: string|null}>  $fields
     */
    public function __construct(
        public string $versionId,
        public string $blockId,
        public string $typeKey,
        public int $contractVersion,
        public string $state,
        public array $queryPlan,
        public array $presentation,
        public array $fields,
        public ?string $endpointId,
    ) {}
}

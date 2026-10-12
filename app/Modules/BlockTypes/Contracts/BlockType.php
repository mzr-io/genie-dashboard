<?php

namespace App\Modules\BlockTypes\Contracts;

/** A validated Block Type package at one contract version, as the registry holds it. */
final readonly class BlockType
{
    /**
     * @param  list<BlockTypeSlot>  $slots
     * @param  array{default_width: int, min_width: int, max_width: int, default_height: string, min_height: string, max_height: string}  $size
     * @param  array<string, string>  $defaultAggregations  slot key => aggregation
     */
    public function __construct(
        public string $key,
        public int $contractVersion,
        public string $title,
        public array $slots,
        public array $size,
        public array $defaultAggregations,
    ) {}

    /** @return array{key: string, contract_version: int, title: string, slots: list<array<string, mixed>>, size: array<string, int|string>, default_aggregations: array<string, string>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'contract_version' => $this->contractVersion,
            'title' => $this->title,
            'slots' => array_map(fn (BlockTypeSlot $slot): array => $slot->toArray(), $this->slots),
            'size' => $this->size,
            'default_aggregations' => $this->defaultAggregations,
        ];
    }
}

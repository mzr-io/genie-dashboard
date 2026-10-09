<?php

namespace App\Modules\Blocks\Contracts;

/** A Draft of a Block as the Admin API shows it. `category` is the category's name. Timestamps are ISO 8601 in UTC. */
final readonly class Draft
{
    /** @param  array<string, mixed>  $config  the lenient `config` document (schema_version 1); empty when the read did not ask for it (the list) */
    public function __construct(
        public string $id,
        public string $blockId,
        public string $name,
        public string $type,
        public ?string $category,
        public string $version,
        public int $revision,
        public int $lockEpoch,
        public int $step,
        public string $ownerMembershipId,
        public string $createdAt,
        public string $updatedAt,
        public ?string $categoryId = null,
        public array $config = [],
    ) {}
}

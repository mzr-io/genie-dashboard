<?php

namespace App\Modules\Blocks\Contracts;

/** A Draft of a Block as the Admin API shows it. Timestamps are ISO 8601 in UTC. */
final readonly class Draft
{
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
    ) {}
}

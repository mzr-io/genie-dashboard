<?php

namespace App\Modules\Blocks\Contracts;

/** One row of Admin > Published blocks (Story 3.19). Timestamps are ISO 8601 in UTC. */
final readonly class PublishedBlock
{
    public function __construct(
        public string $blockId,
        public string $versionId,
        public string $name,
        public string $type,
        public ?string $category,
        public string $version,
        public string $ownerMembershipId,
        public string $updatedAt,
        public string $status,
    ) {}
}

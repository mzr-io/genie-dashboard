<?php

namespace App\Modules\Blocks\Contracts;

/** A version that was published (Story 3.19): what the wizard's success toast and the Published blocks list need. Never a config. */
final readonly class PublishedVersion
{
    public function __construct(
        public string $versionId,
        public string $blockId,
        public string $name,
        public string $type,
        public ?string $category,
        public string $version,
        public string $publishedAt,
    ) {}
}

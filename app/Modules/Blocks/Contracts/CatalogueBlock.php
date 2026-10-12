<?php

namespace App\Modules\Blocks\Contracts;

/**
 * One published Block as the Add-blocks Panel lists it (Story 4.7): User-safe, so it names the Data Source for the filter and the About text but
 * never an Endpoint, Query Plan or sample. `isNew` is the `pending_input` "New" rule applied to the publish time.
 */
final readonly class CatalogueBlock
{
    public function __construct(
        public string $blockId,
        public string $name,
        public ?string $description,
        public ?string $category,
        public string $type,
        public ?string $dataSource,
        public int|string|null $refreshInterval,
        public string $version,
        public bool $isNew,
        public int $width,
        public string $height,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'block_id' => $this->blockId,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'type' => $this->type,
            'data_source' => $this->dataSource,
            'refresh_interval' => $this->refreshInterval,
            'version' => $this->version,
            'is_new' => $this->isNew,
            'default_size' => ['width' => $this->width, 'height' => $this->height],
        ];
    }
}

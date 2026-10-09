<?php

namespace App\Modules\Blocks\Contracts;

/** A category of Blocks in a Workspace (Story 3.4). Management and ordering come with Epic 6. */
final readonly class Category
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $archived,
    ) {}

    /** @return array{id: string, name: string, archived: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'archived' => $this->archived];
    }
}

<?php

namespace App\Modules\Access\Contracts;

/** One user attribute key of the Workspace: the immutable key id and value type, and the editable label. */
final readonly class AttributeKeyRow
{
    public const TYPES = ['text', 'identifier', 'integer'];

    public function __construct(
        public string $id,
        public string $keyId,
        public string $label,
        public string $valueType,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}

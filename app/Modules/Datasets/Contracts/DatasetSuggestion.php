<?php

namespace App\Modules\Datasets\Contracts;

/**
 * A remembered Dataset as a suggestion for a new Draft (Story 3.11c): the record path in its stored form and the typed fields under it. Only paths,
 * types and roles; never a Draft id or any sample text. `updatedAt` is ISO 8601 in UTC.
 */
final readonly class DatasetSuggestion
{
    /** @param  list<array{path: string, type: string, role: string|null}>  $fields */
    public function __construct(
        public string $recordPath,
        public array $fields,
        public string $updatedAt,
    ) {}
}

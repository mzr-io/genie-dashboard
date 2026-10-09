<?php

namespace App\Modules\Mapping\Contracts;

/**
 * What {@see FieldRoles} found: the record-path candidates, the chosen path and every scalar field with its effective role.
 *
 * Candidate: `{path, display, rows, columns, depth, suggested, pagination_like}`. Field: `{path, display, type, example, role, confidence,
 * overridden, in_records, suggestion}` where `suggestion` is null or `{role}`.
 */
final readonly class FieldRoleReport
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $fields
     */
    public function __construct(
        public array $candidates,
        public bool $needsChoice,
        public ?string $recordPath,
        public ?string $recordPathDisplay,
        public array $fields,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'record_path' => $this->recordPath,
            'record_path_display' => $this->recordPathDisplay,
            'needs_choice' => $this->needsChoice,
            'candidates' => $this->candidates,
            'fields' => $this->fields,
        ];
    }
}

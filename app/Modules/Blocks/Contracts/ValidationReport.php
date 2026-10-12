<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The latest ValidationReport of a Draft (Story 3.18), bound to the Draft's `subject_revision`. `status` is the effective one: a stored `passed`,
 * `failed` or `unreachable` reads as `stale` once the Draft's revision has moved past `subjectRevision`. Value-free: a mismatch is
 * `{path, slot, type, expected}` (a stored path, a slot key, why it did not match and the slot's value type), never a sample value.
 */
final readonly class ValidationReport
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const UNREACHABLE = 'unreachable';

    public const STALE = 'stale';

    /**
     * @param  list<array{path: string|null, slot: string|null, type: string, expected: string|null}>  $mismatches
     */
    public function __construct(
        public string $id,
        public string $draftId,
        public string $status,
        public string $storedStatus,
        public ?string $reason,
        public int $subjectRevision,
        public int $draftRevision,
        public array $mismatches,
        public string $version,
        public ?string $endpointId,
        public ?int $endpointRevision,
        public string $validatedAt,
    ) {}

    /** The report allows Publish (Story 3.19): passed, and for the Draft's current revision. */
    public function allowsPublish(): bool
    {
        return $this->status === self::PASSED && $this->subjectRevision === $this->draftRevision;
    }
}

<?php

namespace App\Platform\Operations;

/**
 * One asynchronous request as the kernel stores it. `result` is the small summary the handler returned (a few enums,
 * numbers and short slugs); it is never a body, a secret or a URL query. Only the membership that asked may read it.
 */
final readonly class Operation
{
    /**
     * @param  array<string, string|int|bool|null>|null  $result
     * @param  string  $expiresAt  ISO 8601, UTC
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $kind,
        public string $requesterMembershipId,
        public string $subjectType,
        public ?string $subjectId,
        public ?int $subjectRevision,
        public OperationStatus $status,
        public string $expiresAt,
        public ?string $requestId,
        public ?array $result,
    ) {}
}

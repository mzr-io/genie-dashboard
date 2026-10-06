<?php

namespace App\Modules\Identity\Application;

/** A row of `invitations`, typed. */
final readonly class InvitationRecord
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $email,
        public string $role,
        public string $tokenHash,
        public string $expiresAt,
        public ?string $usedAt,
    ) {}

    public static function fromRow(?object $row): ?self
    {
        if ($row === null) {
            return null;
        }

        /** @var object{id: string, workspace_id: string, email: string, role: string, token_hash: string, expires_at: string, used_at: string|null} $row */
        return new self($row->id, $row->workspace_id, $row->email, $row->role, $row->token_hash, $row->expires_at, $row->used_at);
    }
}

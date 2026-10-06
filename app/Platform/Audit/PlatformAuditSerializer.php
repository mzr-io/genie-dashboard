<?php

namespace App\Platform\Audit;

/** The kernel's audit allowlist: operator actions mirrored into the Workspace log. Emails are hashed. */
final class PlatformAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'platform';
    }

    public function fields(): array
    {
        return [
            'workspace_id' => AuditField::Id,
            'invitation_id' => AuditField::Id,
            'role' => AuditField::Enum,
            'email' => AuditField::Hashed,
        ];
    }
}

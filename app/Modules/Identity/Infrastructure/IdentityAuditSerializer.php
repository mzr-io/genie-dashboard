<?php

namespace App\Modules\Identity\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Identity's audit allowlist: sign-in outcomes, invitations, passwords. Emails and headers are hashed. */
final class IdentityAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'identity';
    }

    public function fields(): array
    {
        return [
            'user_id' => AuditField::Id,
            'membership_id' => AuditField::Id,
            'invitation_id' => AuditField::Id,
            'from_workspace_id' => AuditField::Id,
            'to_workspace_id' => AuditField::Id,
            'area' => AuditField::Enum,
            'reason' => AuditField::Enum,
            'membership' => AuditField::Enum,
            'email' => AuditField::Hashed,
            'username' => AuditField::Hashed,
        ];
    }
}

<?php

namespace App\Modules\Access\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Access's audit allowlist: IDs and enums plain, names and attribute values as keyed hashes. */
final class AccessAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'access';
    }

    public function fields(): array
    {
        return [
            'membership_id' => AuditField::Id,
            'user_id' => AuditField::Id,
            'group_id' => AuditField::Id,
            'role' => AuditField::Enum,
            'status' => AuditField::Enum,
            'permission' => AuditField::Enum,
            'area' => AuditField::Enum,
            'reason' => AuditField::Enum,
            'attribute_key' => AuditField::Enum,
            'attribute_value' => AuditField::Hashed,
            'email' => AuditField::Hashed,
            'name' => AuditField::Hashed,
        ];
    }
}

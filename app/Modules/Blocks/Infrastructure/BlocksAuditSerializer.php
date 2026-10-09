<?php

namespace App\Modules\Blocks\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Blocks' audit allowlist: the Block and Draft IDs, the Block Type key and the revision in the clear; the name as a keyed hash. Never a config, a sample or a mapping. */
final class BlocksAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'blocks';
    }

    public function fields(): array
    {
        return [
            'block_id' => AuditField::Id,
            'draft_id' => AuditField::Id,
            'block_type' => AuditField::Enum,
            'name' => AuditField::Hashed,
            'revision' => AuditField::Count,
        ];
    }
}

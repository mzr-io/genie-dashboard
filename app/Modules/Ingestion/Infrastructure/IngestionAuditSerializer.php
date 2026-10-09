<?php

namespace App\Modules\Ingestion\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Ingestion's audit allowlist: a health change carries the Data Source id and the two statuses (enums), nothing else (Story 2.18). */
final class IngestionAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'ingestion';
    }

    public function fields(): array
    {
        return [
            'data_source_id' => AuditField::Id,
            'from' => AuditField::Enum,
            'to' => AuditField::Enum,
        ];
    }
}

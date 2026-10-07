<?php

namespace App\Modules\Connector\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Connector's audit allowlist: the entry ID, host, scheme and port in the clear (they are not personal data), no emails. */
final class ConnectorAuditSerializer implements AuditSerializer
{
    public function module(): string
    {
        return 'connector';
    }

    public function fields(): array
    {
        return [
            'entry_id' => AuditField::Id,
            'host' => AuditField::Host,
            'scheme' => AuditField::Enum,
            'port' => AuditField::Count,
        ];
    }
}

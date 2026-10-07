<?php

namespace App\Modules\Connector\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Connector's audit allowlist: the entry ID, host, scheme, port, block reason, grant ID and CIDR in the clear (they are not personal data), no emails, never a resolved address; a grant's free-text reason is hashed. */
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
            // Egress: the block reason is an enum (never an address); the grant carries its CIDR, ID and a hashed reason.
            'reason' => AuditField::Enum,
            'cidr' => AuditField::Cidr,
            'grant_id' => AuditField::Id,
            'grant_reason' => AuditField::Hashed,
        ];
    }
}

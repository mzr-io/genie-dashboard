<?php

namespace App\Modules\Connector\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Connector's audit allowlist: the entry and Data Source IDs, host, scheme, port, block reason, grant ID and CIDR in the clear (they are not personal data), no emails, never a resolved address; a grant's free-text reason is hashed; a credential change carries the slot kind, key version and a keyed hash of the value, never the value. */
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
            // Data Sources (Story 2.3): the URL's parts and the limits in the clear, never the path; headers as a count and one keyed hash of the map.
            'data_source_id' => AuditField::Id,
            'auth_type' => AuditField::Enum,
            'timeout_seconds' => AuditField::Count,
            'max_response_bytes' => AuditField::Count,
            'max_pages' => AuditField::Count,
            'live_capable' => AuditField::Enum,
            'header_count' => AuditField::Count,
            'headers' => AuditField::Hashed,
            'revision' => AuditField::Count,
            // Authentication (Story 2.4): the API key's name (hashed) and placement; a secret change is the slot kind, purpose, key version and action in the clear and one keyed hash of the value.
            'api_key_name' => AuditField::Hashed,
            'api_key_placement' => AuditField::Enum,
            'slot' => AuditField::Enum,
            'purpose' => AuditField::Enum,
            'key_version' => AuditField::Count,
            'action' => AuditField::Enum,
            'value_hash' => AuditField::Hashed,
        ];
    }
}

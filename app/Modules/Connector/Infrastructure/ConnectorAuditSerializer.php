<?php

namespace App\Modules\Connector\Infrastructure;

use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditSerializer;

/** Connector's audit allowlist: the entry and Data Source IDs, host, scheme, port, block reason, grant ID and CIDR in the clear (they are not personal data), no emails, never a resolved address; a grant's free-text reason is hashed; an Endpoint carries its ids, method, counts and keyed hashes of the path and bindings; a credential change carries the slot kind, key version and a keyed hash of the value, never the value. */
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
            // Pagination (Story 2.11): the style as an enum, the page size as a count, the parameter and path names as keyed hashes.
            'pagination_style' => AuditField::Enum,
            'pagination_param' => AuditField::Hashed,
            'pagination_size_param' => AuditField::Hashed,
            'pagination_size' => AuditField::Count,
            'pagination_records_path' => AuditField::Hashed,
            'pagination_cursor_path' => AuditField::Hashed,
            'header_count' => AuditField::Count,
            'headers' => AuditField::Hashed,
            'revision' => AuditField::Count,
            // Authentication (Story 2.4): the API key's name (hashed) and placement; a secret change is the slot kind, purpose, key version and action in the clear and one keyed hash of the value.
            'api_key_name' => AuditField::Hashed,
            'api_key_placement' => AuditField::Enum,
            // OAuth2 client credentials (Story 2.7): the token URL, client ID and scope as keyed hashes (the client secret is audited as any other slot).
            'oauth_token_url' => AuditField::Hashed,
            'oauth_client_id' => AuditField::Hashed,
            'oauth_scope' => AuditField::Hashed,
            // Endpoints (Story 2.9): ids, the method, counts and the flag in the clear; the path and the bindings (parameters, headers, body template) as keyed hashes.
            'endpoint_id' => AuditField::Id,
            'method' => AuditField::Enum,
            'param_count' => AuditField::Count,
            'read_only_query' => AuditField::Enum,
            'path' => AuditField::Hashed,
            'bindings' => AuditField::Hashed,
            // An Endpoint test (Story 2.10): the revision tested, in the clear (the id and the method are above); never a value, the path or a body.
            'endpoint_revision' => AuditField::Count,
            'slot' => AuditField::Enum,
            'purpose' => AuditField::Enum,
            'key_version' => AuditField::Count,
            'action' => AuditField::Enum,
            'value_hash' => AuditField::Hashed,
        ];
    }
}

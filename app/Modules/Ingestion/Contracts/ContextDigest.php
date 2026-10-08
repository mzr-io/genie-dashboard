<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The per-Workspace key of the bound context of a fetch key (Story 2.14): `digest_key_ws_v` is HKDF-SHA256 of the platform's `digest` key with
 * info `fkctx|{workspace_id}|v1`. A port, so a test injects a key and the key file is read in one place.
 */
interface ContextDigest
{
    /** The 32-byte key, or null when the `digest` key is missing, unreadable or the wrong size. */
    public function key(string $workspaceId): ?string;
}

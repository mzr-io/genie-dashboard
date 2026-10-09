<?php

namespace App\Modules\Ingestion\Infrastructure;

use App\Modules\Ingestion\Contracts\ContextDigest;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Reads the 32-byte `digest` key (base64, the `key-digest` mount, `dashflow.secrets.digest_key_path`) and derives the key of one Workspace:
 * HKDF-SHA256 with info `fkctx|{workspace_id}|v1`. A missing, unreadable or wrong-sized key is null (a bound key cannot be made); the
 * raw key is wiped after use and never part of a message.
 */
final class KeyFileContextDigest implements ContextDigest
{
    public function __construct(private readonly Repository $config) {}

    public function key(string $workspaceId): ?string
    {
        $configured = $this->config->get('dashflow.secrets.digest_key_path.value');
        $path = is_string($configured) && $configured !== '' ? $configured : '/run/secrets/key-digest';

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return null;
            }

            try {
                return hash_hkdf('sha256', $key, 32, 'fkctx|'.strtolower($workspaceId).'|v1');
            } finally {
                sodium_memzero($key);
            }
        } catch (Throwable) {
            return null;
        }
    }
}

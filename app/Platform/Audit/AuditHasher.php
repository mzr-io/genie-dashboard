<?php

namespace App\Platform\Audit;

use InvalidArgumentException;

/**
 * Keyed hash for audited values (HMAC-SHA256). The key is derived from APP_KEY by a domain-separated
 * HMAC, the same pattern as the job signer, so the raw APP_KEY never hashes audit data.
 */
final class AuditHasher
{
    public const CONTEXT = 'dashflow.audit.value-hash.v1';

    public const PREFIX = 'hmac-sha256:';

    private readonly string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new InvalidArgumentException('APP_KEY is required to hash audit values.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        $this->key = hash_hmac('sha256', self::CONTEXT, $appKey, true);
    }

    public function hash(mixed $value): string
    {
        $canonical = is_scalar($value) || $value === null
            ? json_encode([get_debug_type($value), $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::PREFIX.hash_hmac('sha256', $canonical, $this->key);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map($this->normalize(...), $value);
            ksort($value);

            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Cannot hash a value of type '.get_debug_type($value).'.');
        }

        return $value;
    }
}

<?php

namespace App\Support\Queue;

use InvalidArgumentException;

/**
 * HMAC-SHA256 signature over the immutable core of a queued payload.
 *
 * The signing key is derived from APP_KEY with a domain-separated HMAC, so the
 * raw APP_KEY never signs payloads. Rotating APP_KEY invalidates the signatures
 * of jobs that are already queued.
 *
 * The signature covers `job`, `uuid`, `maxTries`, `timeout`, `backoff` and the
 * whole `data` object (key order normalized, because the queue's Lua scripts
 * re-encode the payload). Fields that Laravel or Horizon rewrite after
 * enqueueing (`attempts`, `tags`, `pushedAt`, `retryUntil`, `retry_of`, ...)
 * are deliberately not signed.
 */
final class JobSigner
{
    public const CONTEXT = 'dashflow.queue.job-signature.v1';

    public const FIELD = 'signature';

    private readonly string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new InvalidArgumentException('APP_KEY is required to sign queued jobs.');
        }

        $this->key = hash_hmac('sha256', self::CONTEXT, self::decodeKey($appKey), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sign(array $payload): array
    {
        $payload[self::FIELD] = $this->signature($payload);

        return $payload;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function verify(array $payload): bool
    {
        $given = $payload[self::FIELD] ?? null;

        if (! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($this->signature($payload), $given);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function signature(array $payload): string
    {
        $canonical = json_encode([
            self::scalar($payload['job'] ?? null),
            self::scalar($payload['uuid'] ?? null),
            self::normalize($payload['maxTries'] ?? null),
            self::normalize($payload['timeout'] ?? null),
            self::normalize($payload['backoff'] ?? null),
            self::normalize($payload['data'] ?? null),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $canonical, $this->key);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalize(...), $value);
        ksort($value);

        return $value;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function decodeKey(string $appKey): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            return $decoded === false ? $appKey : $decoded;
        }

        return $appKey;
    }
}

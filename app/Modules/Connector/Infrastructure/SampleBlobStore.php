<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\SampleBlobUnavailable;
use App\Platform\Tenancy\TenantCache;
use JsonException;
use Throwable;

/**
 * The encrypted, short-lived store of Sample Responses (Story 2.10), in the Valkey cache store through {@see TenantCache}
 * under `sample:{operation_id}` (the Workspace prefix is the cache's). The raw body text is sealed with libsodium
 * `secretbox` under the 32-byte `data` key (base64, read from the `key-data` mount, which `web` and the workers hold); the
 * raw text is the only form that keeps number lexemes. The sealed payload names its purpose (`sample`), the Workspace, the
 * Operation, the requesting membership and the Endpoint with the revision that was tested, and all are verified on read: an
 * entry that does not match, or that cannot be opened, is a miss.
 *
 * Unlike the token cache it fails closed: with no usable key, or a cache that refuses the write, nothing is stored and
 * {@see SampleBlobUnavailable} is raised. A sample is never cached in the clear and never written anywhere else.
 */
final class SampleBlobStore
{
    private const ENVELOPE_VERSION = 1;

    private const PURPOSE = 'sample';

    public function __construct(
        private readonly TenantCache $cache,
        private readonly SecretSettings $settings,
    ) {}

    /**
     * @throws SampleBlobUnavailable
     */
    public function put(string $workspaceId, string $operationId, string $membershipId, string $endpointId, int $endpointRevision, #[\SensitiveParameter] string $body, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new SampleBlobUnavailable('expired');
        }

        $key = $this->key() ?? throw new SampleBlobUnavailable('key_unavailable');

        try {
            $payload = json_encode([
                'v' => self::ENVELOPE_VERSION,
                'purpose' => self::PURPOSE,
                'workspace_id' => strtolower($workspaceId),
                'operation_id' => strtolower($operationId),
                'membership_id' => strtolower($membershipId),
                'endpoint_id' => strtolower($endpointId),
                'endpoint_revision' => $endpointRevision,
                'body' => $body,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $sealed = base64_encode($nonce.sodium_crypto_secretbox($payload, $nonce, $key));

            if (! $this->cache->put($workspaceId, self::name($operationId), $sealed, $ttlSeconds)) {
                throw new SampleBlobUnavailable('store_refused');
            }
        } catch (SampleBlobUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new SampleBlobUnavailable('store_failed');
        } finally {
            sodium_memzero($key);

            if (isset($payload)) {
                sodium_memzero($payload);
            }
        }
    }

    /** The body for the membership and Endpoint revision it was stored for, or null (a miss, a mismatch, a body that cannot be opened). */
    public function get(string $workspaceId, string $operationId, string $membershipId, string $endpointId, int $endpointRevision): ?string
    {
        $key = $this->key();

        if ($key === null) {
            return null;
        }

        try {
            $stored = $this->cache->get($workspaceId, self::name($operationId));
        } catch (Throwable) {
            sodium_memzero($key);

            return null;
        }

        $plain = is_string($stored) ? $this->open($key, $stored) : null;
        sodium_memzero($key);

        if ($plain === null) {
            return null;
        }

        try {
            $payload = json_decode($plain, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        } finally {
            sodium_memzero($plain);
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::ENVELOPE_VERSION
            || ($payload['purpose'] ?? null) !== self::PURPOSE
            || ($payload['workspace_id'] ?? null) !== strtolower($workspaceId)
            || ($payload['operation_id'] ?? null) !== strtolower($operationId)
            || ($payload['membership_id'] ?? null) !== strtolower($membershipId)
            || ($payload['endpoint_id'] ?? null) !== strtolower($endpointId)
            || ($payload['endpoint_revision'] ?? null) !== $endpointRevision
            || ! is_string($payload['body'] ?? null)) {
            return null;
        }

        return $payload['body'];
    }

    public function forget(string $workspaceId, string $operationId): void
    {
        try {
            $this->cache->forget($workspaceId, self::name($operationId));
        } catch (Throwable) {
            // Nothing to drop if the store is down; the entry expires with its TTL.
        }
    }

    private static function name(string $operationId): string
    {
        return 'sample:'.strtolower($operationId);
    }

    private function open(string $key, string $stored): ?string
    {
        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } catch (\SodiumException) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /** The 32-byte key from the `key-data` file, or null when there is none. */
    private function key(): ?string
    {
        $path = $this->settings->dataKeyPath();

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        } catch (Throwable) {
            // Treated as no key.
        }

        return null;
    }
}

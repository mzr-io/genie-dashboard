<?php

namespace App\Modules\Connector\Infrastructure;

use App\Platform\Tenancy\TenantCache;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The encrypted cache of OAuth access tokens (Story 2.7), in the Valkey cache store through {@see TenantCache} under
 * `oauth:{data_source_id}:{secret_version}` (the Workspace prefix is the cache's). A token is never stored in the clear:
 * the value is a libsodium `secretbox` under the 32-byte `key-token` (base64, read from the mount, `worker-connector` only).
 * The sealed payload names its purpose (`token`), Workspace, Data Source and `secret_version`, plus a digest of the token
 * URL, client ID and scope it was requested with, and all are verified on read: an entry that does not match, or that
 * cannot be opened, is a miss. A replaced client secret has a new version and so a new key: an old token is never read.
 *
 * Without a usable key (the dev placeholder, a missing file) nothing is cached and nothing is read, so a token lives for
 * the one call; one warning per process says so, naming no value.
 */
final class OAuthTokenCache
{
    private const ENVELOPE_VERSION = 1;

    private const PURPOSE = 'token';

    private bool $warned = false;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly SecretSettings $settings,
        private readonly LoggerInterface $log,
    ) {}

    /** The digest that ties a token to the endpoint, client and scope it was requested with. */
    public static function binding(string $tokenUrl, string $clientId, ?string $scope): string
    {
        return hash('sha256', $tokenUrl."\n".$clientId."\n".($scope ?? ''));
    }

    public function get(string $workspaceId, string $dataSourceId, int $secretVersion, string $binding): ?string
    {
        $key = $this->key();

        if ($key === null) {
            return null;
        }

        try {
            $stored = $this->cache->get($workspaceId, self::name($dataSourceId, $secretVersion));
        } catch (Throwable) {
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
            || ($payload['data_source_id'] ?? null) !== strtolower($dataSourceId)
            || ($payload['secret_version'] ?? null) !== $secretVersion
            || ($payload['binding'] ?? null) !== $binding
            || ! is_string($payload['token'] ?? null) || $payload['token'] === '') {
            return null;
        }

        return $payload['token'];
    }

    /** Stores the token for `$ttl` seconds; does nothing for a TTL of zero or less or when there is no key. */
    public function put(string $workspaceId, string $dataSourceId, int $secretVersion, string $binding, #[\SensitiveParameter] string $token, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }

        $key = $this->key();

        if ($key === null) {
            return;
        }

        try {
            $payload = json_encode([
                'v' => self::ENVELOPE_VERSION,
                'purpose' => self::PURPOSE,
                'workspace_id' => strtolower($workspaceId),
                'data_source_id' => strtolower($dataSourceId),
                'secret_version' => $secretVersion,
                'binding' => $binding,
                'token' => $token,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $sealed = base64_encode($nonce.sodium_crypto_secretbox($payload, $nonce, $key));
            $this->cache->put($workspaceId, self::name($dataSourceId, $secretVersion), $sealed, $ttl);
        } catch (Throwable) {
            // A cache that cannot be written only costs a token request next time.
        } finally {
            sodium_memzero($key);

            if (isset($payload)) {
                sodium_memzero($payload);
            }
        }
    }

    public function forget(string $workspaceId, string $dataSourceId, int $secretVersion): void
    {
        try {
            $this->cache->forget($workspaceId, self::name($dataSourceId, $secretVersion));
        } catch (Throwable) {
            // Nothing to drop if the store is down.
        }
    }

    /** Drops the entry only while it still holds the token that was rejected: a fresher one another worker stored stays. */
    public function forgetIfRejected(string $workspaceId, string $dataSourceId, int $secretVersion, string $binding, #[\SensitiveParameter] string $rejected): void
    {
        $current = $this->get($workspaceId, $dataSourceId, $secretVersion, $binding);

        if ($current !== null && hash_equals($current, $rejected)) {
            $this->forget($workspaceId, $dataSourceId, $secretVersion);
        }
    }

    private static function name(string $dataSourceId, int $secretVersion): string
    {
        return 'oauth:'.strtolower($dataSourceId).':'.$secretVersion;
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

    /** The 32-byte key from the `key-token` file, or null (warning once) when there is none. */
    private function key(): ?string
    {
        $path = $this->settings->tokenKeyPath();

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        } catch (Throwable) {
            // Treated as no key.
        }

        if (! $this->warned) {
            $this->warned = true;
            $this->log->warning('connector.oauth.token_cache_disabled', ['reason' => 'token_key_unavailable', 'setting' => 'dashflow.secrets.token_key_path']);
        }

        return null;
    }
}

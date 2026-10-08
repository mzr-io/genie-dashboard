<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\AttributesUnavailable;
use App\Modules\Access\Contracts\AttributeVault;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Throwable;

/**
 * The attribute vault (Story 2.12). A value is sealed with libsodium `secretbox` under the 32-byte `data` key (base64, the
 * `key-data` mount, `dashflow.secrets.data_key_path`); the sealed payload names its purpose, the Workspace, the member and
 * the key id with the value, and all are verified on read, so a ciphertext copied to another row does not open. The blind
 * index is a hex HMAC-SHA256 under the 32-byte `digest` key (base64, the `key-digest` mount, `dashflow.secrets.digest_key_path`)
 * over `attr|{workspace}|{key id}|{value}`, so equal values of one key can be found without decrypting.
 *
 * Both keys fail closed: a missing, unreadable or wrong-sized key raises {@see AttributesUnavailable}. Key material is
 * wiped after use and never part of an exception message.
 */
final class SodiumAttributeVault implements AttributeVault
{
    private const ENVELOPE_VERSION = 1;

    private const PURPOSE = 'attribute';

    public function __construct(private readonly Repository $config) {}

    public function assertAvailable(): void
    {
        $data = $this->dataKey();
        $digest = $this->digestKey();
        sodium_memzero($data);
        sodium_memzero($digest);
    }

    public function seal(string $workspaceId, string $membershipId, string $keyId, #[\SensitiveParameter] string $value): string
    {
        $key = $this->dataKey();

        try {
            $payload = json_encode([
                'v' => self::ENVELOPE_VERSION,
                'purpose' => self::PURPOSE,
                'workspace_id' => strtolower($workspaceId),
                'membership_id' => strtolower($membershipId),
                'key_id' => $keyId,
                'value' => $value,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return $nonce.sodium_crypto_secretbox($payload, $nonce, $key);
        } catch (Throwable) {
            throw new AttributesUnavailable('The attribute value could not be sealed.');
        } finally {
            sodium_memzero($key);

            if (isset($payload)) {
                sodium_memzero($payload);
            }
        }
    }

    public function open(string $workspaceId, string $membershipId, string $keyId, string $sealed): string
    {
        $key = $this->dataKey();

        try {
            if (strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                throw new AttributesUnavailable('The attribute value cannot be opened.');
            }

            $plain = sodium_crypto_secretbox_open(
                substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } catch (AttributesUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new AttributesUnavailable('The attribute value cannot be opened.');
        } finally {
            sodium_memzero($key);
        }

        if ($plain === false) {
            throw new AttributesUnavailable('The attribute value cannot be opened.');
        }

        try {
            $payload = json_decode($plain, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AttributesUnavailable('The attribute value cannot be opened.');
        } finally {
            sodium_memzero($plain);
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::ENVELOPE_VERSION
            || ($payload['purpose'] ?? null) !== self::PURPOSE
            || ($payload['workspace_id'] ?? null) !== strtolower($workspaceId)
            || ($payload['membership_id'] ?? null) !== strtolower($membershipId)
            || ($payload['key_id'] ?? null) !== $keyId
            || ! is_string($payload['value'] ?? null)) {
            throw new AttributesUnavailable('The attribute value cannot be opened.');
        }

        return $payload['value'];
    }

    public function blindIndex(string $workspaceId, string $keyId, #[\SensitiveParameter] string $value): string
    {
        $key = $this->digestKey();

        try {
            return hash_hmac('sha256', 'attr|'.strtolower($workspaceId).'|'.$keyId.'|'.$value, $key);
        } finally {
            sodium_memzero($key);
        }
    }

    /** @throws AttributesUnavailable */
    private function dataKey(): string
    {
        return $this->read('dashflow.secrets.data_key_path.value', '/run/secrets/key-data');
    }

    /** @throws AttributesUnavailable */
    private function digestKey(): string
    {
        return $this->read('dashflow.secrets.digest_key_path.value', '/run/secrets/key-digest');
    }

    /** @throws AttributesUnavailable */
    private function read(string $setting, string $default): string
    {
        $configured = $this->config->get($setting);
        $path = is_string($configured) && $configured !== '' ? $configured : $default;

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        } catch (Throwable) {
            // Treated as no key.
        }

        throw new AttributesUnavailable('A key for user attributes is unavailable.');
    }
}

<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\SealedSecret;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SecretsNotConfigured;
use App\Modules\Connector\Contracts\SecretStatus;
use App\Modules\Connector\Contracts\SecretVault;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * The `local` {@see SecretVault}: a libsodium sealed box (`sodium_crypto_box_seal`) to the platform's X25519 public key.
 * Sealing needs only the public key, so `web` can store a credential and cannot read one back; opening needs the private
 * key, which only `worker-connector` mounts (`key-cred`: a file holding the base64 of the 32-byte secret key).
 *
 * The sealed payload is a small versioned envelope (`purpose`, `workspace_id`, `data_source_id`, `slot`, `value`) and
 * `open` verifies the context it is asked for, so a value copied to another Workspace, Data Source or slot is refused.
 * Nothing here logs or returns a plaintext except `open`'s result.
 */
final class LocalSecretVault implements SecretVault
{
    private const ENVELOPE_VERSION = 1;

    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(private readonly SecretSettings $settings) {}

    public function seal(SecretContext $context, #[\SensitiveParameter] string $value): SealedSecret
    {
        // Settings first: with no key, nothing is sealed and nothing can be stored.
        $publicKey = $this->settings->publicKey();
        $version = $this->settings->keyVersion();

        try {
            $envelope = json_encode([
                'v' => self::ENVELOPE_VERSION,
                'purpose' => $context->purpose,
                'workspace_id' => $context->workspaceId,
                'data_source_id' => $context->dataSourceId,
                'slot' => $context->slot,
                'value' => $value,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $sealed = sodium_crypto_box_seal($envelope, $publicKey);
        } catch (JsonException|\SodiumException) {
            throw new SecretsNotConfigured;
        } finally {
            if (isset($envelope)) {
                sodium_memzero($envelope);
            }
        }

        return new SealedSecret($sealed, $version, 'sha256:'.substr(hash('sha256', $publicKey), 0, 32));
    }

    public function open(SecretContext $context, #[\SensitiveParameter] string $ciphertext): string
    {
        $pair = $this->keyPair();

        try {
            $plain = sodium_crypto_box_seal_open($ciphertext, $pair);
        } catch (\SodiumException) {
            throw new SecretRefused;
        } finally {
            sodium_memzero($pair);
        }

        if ($plain === false) {
            throw new SecretRefused;
        }

        try {
            $envelope = json_decode($plain, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SecretRefused;
        } finally {
            sodium_memzero($plain);
        }

        if (! is_array($envelope)
            || ($envelope['v'] ?? null) !== self::ENVELOPE_VERSION
            || ($envelope['purpose'] ?? null) !== $context->purpose
            || ($envelope['workspace_id'] ?? null) !== $context->workspaceId
            || ($envelope['data_source_id'] ?? null) !== $context->dataSourceId
            || ($envelope['slot'] ?? null) !== $context->slot
            || ! is_string($envelope['value'] ?? null)) {
            throw new SecretRefused;
        }

        return $envelope['value'];
    }

    public function status(string $workspaceId, string $dataSourceId): array
    {
        $rows = DB::select(
            'select id, slot, key_version, to_char(updated_at, '.self::STAMP.') as updated from secrets where workspace_id = ? and data_source_id = ? order by slot',
            [$workspaceId, $dataSourceId],
        );

        $statuses = [];

        foreach ($rows as $row) {
            /** @var object{id: string, slot: string, key_version: int|string, updated: string} $row */
            $statuses[$row->slot] = new SecretStatus($row->slot, true, $row->updated, strtolower($row->id), (int) $row->key_version);
        }

        return $statuses;
    }

    /** The key pair from the private key file; a missing, unreadable or malformed file is "no keyring" (the case on `web`). */
    private function keyPair(): string
    {
        $path = $this->settings->keyPath();

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $secret = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw new KeyringUnavailable;
            }

            $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
            sodium_memzero($secret);

            return $pair;
        } catch (KeyringUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new KeyringUnavailable;
        }
    }
}

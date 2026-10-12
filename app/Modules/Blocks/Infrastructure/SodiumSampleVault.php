<?php

namespace App\Modules\Blocks\Infrastructure;

use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\SampleVault;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * The sample vault (Story 3.8): libsodium `secretbox` under the 32-byte `data` key (base64, the `key-data` mount,
 * `dashflow.secrets.data_key_path`), the same key that seals an Endpoint test's body and a user attribute. The sealed text is a header
 * that names the purpose (`sample`), the Workspace and the Draft, then the sample exactly as pasted; the header is checked on opening, so
 * a ciphertext copied to another Draft or Workspace does not open. The stored form is `base64(nonce . box)`.
 *
 * It fails closed: a missing, unreadable or wrong-sized key raises {@see SampleUnavailable} and nothing is sealed. Key material and the
 * clear text are wiped after use and never part of an exception message. It never decodes the sample, as JSON or otherwise.
 */
final class SodiumSampleVault implements SampleVault
{
    private const PURPOSE = 'dashflow.draft-sample.v1';

    public function __construct(private readonly Repository $config) {}

    public function seal(string $workspaceId, string $draftId, #[\SensitiveParameter] string $text): string
    {
        $key = $this->key();

        try {
            $plain = self::header($workspaceId, $draftId).$text;
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
        } catch (Throwable) {
            throw new SampleUnavailable;
        } finally {
            sodium_memzero($key);

            if (isset($plain)) {
                sodium_memzero($plain);
            }
        }
    }

    public function open(string $workspaceId, string $draftId, string $sealed): string
    {
        $key = $this->key();

        try {
            $raw = base64_decode($sealed, true);

            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                throw new SampleUnavailable;
            }

            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        } catch (SampleUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new SampleUnavailable;
        } finally {
            sodium_memzero($key);
        }

        $header = self::header($workspaceId, $draftId);

        if ($plain === false || ! str_starts_with($plain, $header)) {
            throw new SampleUnavailable;
        }

        return substr($plain, strlen($header));
    }

    private static function header(string $workspaceId, string $draftId): string
    {
        return self::PURPOSE."\n".strtolower($workspaceId)."\n".strtolower($draftId)."\n";
    }

    /** @throws SampleUnavailable */
    private function key(): string
    {
        $configured = $this->config->get('dashflow.secrets.data_key_path.value');
        $path = is_string($configured) && $configured !== '' ? $configured : '/run/secrets/key-data';

        try {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;

            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        } catch (Throwable) {
            // Treated as no key.
        }

        throw new SampleUnavailable;
    }
}

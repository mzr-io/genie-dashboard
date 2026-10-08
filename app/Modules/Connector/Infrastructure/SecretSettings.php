<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\SecretsNotConfigured;
use App\Platform\Json\InvalidLimitSetting;
use Illuminate\Contracts\Config\Repository;

/**
 * The `dashflow.secrets` settings (Story 2.4, every one `pending_input` but the key path): the platform's `cred` public key
 * (base64, 32 bytes) and key version, and where the private key file is read. A public key that is unset or not a valid
 * key, or a version that is not a positive whole number, means saving a secret is refused: nothing is invented.
 */
final class SecretSettings
{
    public function __construct(private readonly Repository $config) {}

    /** @throws SecretsNotConfigured */
    public function publicKey(): string
    {
        $value = $this->config->get('dashflow.secrets.cred_public_key.value');
        $key = is_string($value) ? base64_decode(trim($value), true) : false;

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new SecretsNotConfigured;
        }

        return $key;
    }

    /** @throws SecretsNotConfigured */
    public function keyVersion(): int
    {
        $value = $this->config->get('dashflow.secrets.cred_key_version.value');

        if (is_int($value) ? $value < 1 : (! is_string($value) || preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) !== 1)) {
            throw new SecretsNotConfigured;
        }

        return (int) $value;
    }

    /** Where the token cache key is read (worker-connector only). */
    public function tokenKeyPath(): string
    {
        $value = $this->config->get('dashflow.secrets.token_key_path.value');

        return is_string($value) && $value !== '' ? $value : '/run/secrets/key-token';
    }

    /**
     * Seconds taken off a token's `expires_in` before it is cached; unset means none (no number is invented).
     *
     * @throws InvalidLimitSetting when it is set but is not a whole number of zero or more (the caller fails closed)
     */
    public function tokenSkewSeconds(): int
    {
        $value = $this->config->get('dashflow.oauth.token_skew_seconds.value');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return 0;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidLimitSetting('oauth_token_skew_seconds');
    }

    public function keyPath(): string
    {
        $value = $this->config->get('dashflow.secrets.cred_key_path.value');

        return is_string($value) && $value !== '' ? $value : '/run/secrets/key-cred';
    }
}

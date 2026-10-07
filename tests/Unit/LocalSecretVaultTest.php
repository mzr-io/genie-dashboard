<?php

use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SecretsNotConfigured;
use App\Modules\Connector\Infrastructure\LocalSecretVault;
use App\Modules\Connector\Infrastructure\SecretSettings;
use Illuminate\Config\Repository;

// Story 2.4: the sealed box. Sealing needs only the public key; opening needs the private key file, which only the worker mounts.
function vaultWith(?string $public, mixed $version, string $path): LocalSecretVault
{
    return new LocalSecretVault(new SecretSettings(new Repository(['dashflow' => ['secrets' => [
        'cred_public_key' => ['value' => $public], 'cred_key_version' => ['value' => $version], 'cred_key_path' => ['value' => $path],
    ]]])));
}

function keyFiles(): array
{
    $pair = sodium_crypto_box_keypair();
    $file = tempnam(sys_get_temp_dir(), 'vk');
    file_put_contents($file, base64_encode(sodium_crypto_box_secretkey($pair))."\n");

    return [base64_encode(sodium_crypto_box_publickey($pair)), $file];
}

it('seals to the public key and opens only with the private key file, verifying the context', function () {
    [$public, $file] = keyFiles();
    $context = new SecretContext('ws-1', 'ds-1', 'bearer_token');
    $sealed = vaultWith($public, '2', '/nonexistent')->seal($context, 'CANARY-v');

    expect($sealed->keyVersion)->toBe(2)->and($sealed->keyRef)->toStartWith('sha256:')
        ->and($sealed->ciphertext)->not->toContain('CANARY-v')
        ->and(print_r($sealed, true))->not->toContain('CANARY-v');

    expect(fn () => vaultWith($public, '2', '/nonexistent')->open($context, $sealed->ciphertext))->toThrow(KeyringUnavailable::class);

    $worker = vaultWith($public, '2', $file);
    expect($worker->open($context, $sealed->ciphertext))->toBe('CANARY-v');

    foreach ([new SecretContext('ws-2', 'ds-1', 'bearer_token'), new SecretContext('ws-1', 'ds-2', 'bearer_token'), new SecretContext('ws-1', 'ds-1', 'api_key')] as $other) {
        expect(fn () => $worker->open($other, $sealed->ciphertext))->toThrow(SecretRefused::class);
    }

    expect(fn () => $worker->open($context, 'garbage'))->toThrow(SecretRefused::class);
    unlink($file);
});

it('refuses to seal with an unset or invalid public key or version', function (?string $key, mixed $version) {
    expect(fn () => vaultWith($key, $version, '/x')->seal(new SecretContext('w', 'd', 'api_key'), 'v'))->toThrow(SecretsNotConfigured::class);
})->with([[null, '1'], ['***', '1'], [base64_encode('short'), '1'], [base64_encode(str_repeat('k', 32)), null], [base64_encode(str_repeat('k', 32)), 'x']]);

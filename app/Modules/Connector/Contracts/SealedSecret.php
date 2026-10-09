<?php

namespace App\Modules\Connector\Contracts;

/**
 * A value sealed to the platform key: opaque ciphertext, the version of the key and the fingerprint of the public key it
 * was sealed to. It is stored and never sent to a client, logged or audited, so it hides its bytes from dumps.
 */
final readonly class SealedSecret
{
    public function __construct(
        #[\SensitiveParameter] public string $ciphertext,
        public int $keyVersion,
        public string $keyRef,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['keyVersion' => $this->keyVersion, 'keyRef' => $this->keyRef];
    }
}

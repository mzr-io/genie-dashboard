<?php

namespace App\Modules\Connector\Contracts;

/**
 * The write-only store of Data Source credentials (Story 2.4; AR-26, AR-50). `seal` encrypts a value to the platform's
 * public key, so every role can write one and only `worker-connector`, which alone holds the private key (`key-cred`),
 * can `open` it. `status` reports only that a slot is set and when.
 */
interface SecretVault
{
    /**
     * @throws SecretsNotConfigured when the public key or its version is unset or invalid
     */
    public function seal(SecretContext $context, #[\SensitiveParameter] string $value): SealedSecret;

    /**
     * The plaintext of a sealed value, only where the private key file is readable.
     *
     * @throws KeyringUnavailable on any role without the key (`web`)
     * @throws SecretRefused when the value is damaged or was sealed for another Workspace, Data Source, slot or purpose
     */
    public function open(SecretContext $context, #[\SensitiveParameter] string $ciphertext): string;

    /**
     * The status of every slot of a Data Source, keyed by slot (only the slots that hold a value), in the caller's
     * Workspace transaction. Never a value or ciphertext.
     *
     * @return array<string, SecretStatus>
     */
    public function status(string $workspaceId, string $dataSourceId): array;
}

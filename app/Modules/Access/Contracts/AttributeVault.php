<?php

namespace App\Modules\Access\Contracts;

/**
 * Seals and opens user attribute values and computes their blind index (Story 2.12). It fails closed: with the `data` or
 * the `digest` key unusable it raises {@see AttributesUnavailable} and nothing is sealed, opened or indexed.
 */
interface AttributeVault
{
    public const BLIND_INDEX_VERSION = 1;

    /** @throws AttributesUnavailable when either key is unusable */
    public function assertAvailable(): void;

    /**
     * The `data` key alone is usable: enough to open a stored value. `worker-connector` mounts no `digest` key (AR-50), so it
     * checks this and never {@see self::assertAvailable()}.
     *
     * @throws AttributesUnavailable
     */
    public function assertReadable(): void;

    /**
     * @return string the sealed value (binary)
     *
     * @throws AttributesUnavailable
     */
    public function seal(string $workspaceId, string $membershipId, string $keyId, #[\SensitiveParameter] string $value): string;

    /**
     * @throws AttributesUnavailable the key is unusable, or the sealed value does not open or names another Workspace, member or key
     */
    public function open(string $workspaceId, string $membershipId, string $keyId, string $sealed): string;

    /**
     * Hex HMAC-SHA256 under the `digest` key over `attr|{workspace}|{key id}|{value}`.
     *
     * @throws AttributesUnavailable
     */
    public function blindIndex(string $workspaceId, string $keyId, #[\SensitiveParameter] string $value): string;
}

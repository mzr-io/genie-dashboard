<?php

namespace App\Modules\Connector\Contracts;

/** What a sealed value belongs to: sealing binds it, and opening verifies it, so a copied value is refused. */
final readonly class SecretContext
{
    public const PURPOSE_CRED = 'cred';

    public function __construct(
        public string $workspaceId,
        public string $dataSourceId,
        public string $slot,
        public string $purpose = self::PURPOSE_CRED,
    ) {}
}

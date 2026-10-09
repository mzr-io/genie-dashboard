<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The platform's `cred` public key or key version is unset or invalid: saving a secret is refused (HTTP 503, `connector.secrets_not_configured`) and nothing is stored. */
final class SecretsNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Credential storage is not configured.');
    }
}

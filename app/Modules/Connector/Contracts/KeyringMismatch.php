<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * A stored secret was sealed to another platform key than the one this process holds (a rotated or wrong `key-cred`):
 * it cannot be opened here. Distinct from {@see KeyringUnavailable} (no key at all) so the operator log can tell them apart.
 */
final class KeyringMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The stored secret was sealed to another credential key.');
    }
}

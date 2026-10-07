<?php

namespace App\Modules\Identity\Contracts;

use SensitiveParameter;

/**
 * A fresh invitation token and its stored form. Only `hash` (SHA-256 of the 256-bit token) is ever persisted;
 * the plain `token` goes into the email and nowhere else. It never appears in a dump.
 */
final readonly class InvitationSecret
{
    public function __construct(#[SensitiveParameter] public string $token, public string $hash) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'hash' => '[redacted]'];
    }
}

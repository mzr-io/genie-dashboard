<?php

namespace App\Modules\Identity\Application;

/** A 256-bit invitation token and its stored form: only the SHA-256 hash is ever persisted. */
final class InvitationToken
{
    /** 32 random bytes in URL-safe base64 without padding. */
    public const PATTERN = '/\A[A-Za-z0-9_-]{43}\z/D';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function wellFormed(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }
}

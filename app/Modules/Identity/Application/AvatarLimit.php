<?php

namespace App\Modules\Identity\Application;

/**
 * The largest avatar upload in bytes (0 = no application limit): the `profile.avatar_max_bytes` tunable (`pending_input`), or PHP's own
 * `upload_max_filesize` while it is unset, so no Dashflow number is invented.
 */
final class AvatarLimit
{
    public static function bytes(?string $ini = null): int
    {
        $configured = config('dashflow.tunables.profile.avatar_max_bytes.value');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return self::iniBytes($ini ?? (string) ini_get('upload_max_filesize'));
    }

    /**
     * PHP shorthand ("2M", "1.5M", "512K", "1G", or plain bytes) as bytes. 0 means "no application limit":
     * PHP's `-1` (unlimited), `0` and anything unreadable. Large values are clamped, never wrapped.
     */
    public static function iniBytes(string $value): int
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)b?$/i', trim($value), $parts)) {
            return 0;
        }

        $multiplier = match (strtolower($parts[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        $bytes = (float) $parts[1] * $multiplier;

        return $bytes >= PHP_INT_MAX ? PHP_INT_MAX : (int) floor($bytes);
    }

    /** The refusal text for an oversize picture. */
    public static function tooLargeMessage(): string
    {
        $limit = self::bytes();

        return $limit > 0
            ? 'Choose an image no larger than '.self::label($limit).'.'
            : 'Choose a smaller image.';
    }

    /** "2 MB" style text for the refusal message. */
    public static function label(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' bytes',
        };
    }
}

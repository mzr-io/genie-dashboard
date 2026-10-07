<?php

namespace App\Modules\Connector\Contracts;

/**
 * Request header names that a caller may not set. The egress transport owns the first group (a caller's `Host` would
 * reach another virtual host on the pinned address); credentials are the second (they are write-only secrets, never
 * default headers: Story 2.4). The one list serves the transport and the Data Source default-header validation.
 */
final class ReservedHeaders
{
    /** Names the transport sets itself, as a case-insensitive pattern. */
    public const TRANSPORT = '/\A(?:host|content-length|transfer-encoding|connection|expect|te|upgrade|proxy-.*)\z/Di';

    /** Names that carry credentials. */
    public const CREDENTIALS = '/\A(?:authorization|proxy-authorization|cookie)\z/Di';

    public static function transport(string $name): bool
    {
        return preg_match(self::TRANSPORT, $name) === 1;
    }

    public static function credential(string $name): bool
    {
        return preg_match(self::CREDENTIALS, $name) === 1;
    }
}

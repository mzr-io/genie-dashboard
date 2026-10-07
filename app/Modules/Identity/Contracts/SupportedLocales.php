<?php

namespace App\Modules\Identity\Contracts;

/**
 * The locales the message catalogue supports: the one list the Profile page offers and the server accepts.
 * English only in the MVP; a later locale is one more entry here and one more catalogue file.
 */
final class SupportedLocales
{
    public const ALL = ['en'];

    /** The locale a person without a saved choice gets. */
    public const DEFAULT = 'en';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}

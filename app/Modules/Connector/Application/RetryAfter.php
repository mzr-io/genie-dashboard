<?php

namespace App\Modules\Connector\Application;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses the `Retry-After` header of a response (Story 2.17): exactly one value, either delta-seconds (digits only) or an HTTP-date (IMF-fixdate,
 * the obsolete RFC 850 form or asctime), as whole seconds from `$now`, never negative. Anything else (absent, repeated, empty, malformed) is null.
 * The value is never kept, logged or recorded: only the number it yields leaves here.
 */
final class RetryAfter
{
    /** The largest delta kept: the header is untrusted, and a caller clamps to its own cap anyway. */
    private const MAX_SECONDS = 31_536_000;

    private const FORMATS = ['!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y'];

    /** @param  array<string, list<string>>  $headers  keyed by lower-case name */
    public static function seconds(array $headers, DateTimeImmutable $now): ?int
    {
        $values = $headers['retry-after'] ?? [];

        if (count($values) !== 1) {
            return null;
        }

        $value = trim($values[0], " \t");

        if (preg_match('/\A[0-9]{1,10}\z/D', $value) === 1) {
            return min((int) $value, self::MAX_SECONDS);
        }

        if (strlen($value) > 40) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        foreach (self::FORMATS as $format) {
            // asctime pads a one-digit day with a space, which `j` does not take: collapse runs of spaces first.
            $candidate = $format === '!D M j H:i:s Y' ? (string) preg_replace('/ {2,}/', ' ', $value) : $value;
            $date = DateTimeImmutable::createFromFormat($format, $candidate, $utc);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return min(max(0, $date->getTimestamp() - $now->getTimestamp()), self::MAX_SECONDS);
            }
        }

        return null;
    }
}

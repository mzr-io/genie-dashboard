<?php

namespace App\Modules\Connector\Contracts;

/** Resolves a URL reference (a redirect `Location`, a `Link` target) against the URL it came from. */
final class UrlReference
{
    /** The absolute URL `$location` points to, relative to `$base`, without a fragment; null when it cannot be resolved. */
    public static function resolve(string $base, string $location): ?string
    {
        $location = trim((string) preg_replace('/#.*\z/s', '', $location));

        if ($location === '') {
            return null;
        }

        if (preg_match('~\A[A-Za-z][A-Za-z0-9+.-]*:~', $location) === 1) {
            return $location;
        }

        if (preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*:)//([^/?#]*)([^?#]*)~', $base, $b) !== 1) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $b[1].$location;
        }

        if ($location[0] === '/') {
            return $b[1].'//'.$b[2].$location;
        }

        if ($location[0] === '?') {
            return $b[1].'//'.$b[2].$b[3].$location;
        }

        $directory = substr($b[3], 0, (int) strrpos($b[3], '/'));

        return $b[1].'//'.$b[2].$directory.'/'.$location;
    }
}

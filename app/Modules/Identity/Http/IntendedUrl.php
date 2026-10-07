<?php

namespace App\Modules\Identity\Http;

use Illuminate\Http\Request;

/**
 * Which URLs may be remembered as "the page the person was on" and used after sign-in. Same scheme, host
 * and port as this request; never a sign-in or sign-out URL or an API path; and a sign-in only lands on
 * a page that belongs to the area it opened (`/admin` for Admin, everything else for User).
 */
final class IntendedUrl
{
    /** The URL when it is a page of this application that can be returned to, else null. */
    public static function accept(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if ($origin !== strtolower($request->getSchemeAndHttpHost())) {
            return null;
        }

        $path = '/'.trim(rawurldecode($parts['path'] ?? '/'), '/');

        foreach (['login', 'logout', 'api'] as $blocked) {
            if ($path === "/{$blocked}" || str_starts_with($path, "/{$blocked}/")) {
                return null;
            }
        }

        return $url;
    }

    public static function belongsToArea(string $url, ?string $area): bool
    {
        $path = '/'.trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
        $admin = $path === '/admin' || str_starts_with($path, '/admin/');

        return $area === 'admin' ? $admin : ! $admin;
    }
}

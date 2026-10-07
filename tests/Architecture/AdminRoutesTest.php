<?php

use App\Http\Middleware\RequireAdminAccess;
use App\Http\Navigation\ShellNavigation;
use App\Modules\Access\Contracts\Permission;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

// Story 1.19: adding an Admin route without the `admin` middleware, or one that resolves to no permission
// mapping, fails here.

/** @return list<string|null> the key of each use of the admin middleware on the route (null: no key given) */
function adminMiddlewareKeys(Route $route): array
{
    $aliases = app('router')->getMiddleware();
    $keys = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);

        if (($aliases[$name] ?? $name) === RequireAdminAccess::class) {
            $keys[] = $parameters;
        }
    }

    return $keys;
}

function isAdminRoute(Route $route): bool
{
    return str_starts_with((string) $route->getName(), 'admin.')
        || preg_match('#(^|/)admin(/|$)#', $route->uri()) === 1;
}

it('puts the admin middleware on every Admin page route and every /api/v1/admin endpoint', function () {
    $missing = [];
    $seen = 0;

    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (! isAdminRoute($route)) {
            continue;
        }

        $seen++;

        if (adminMiddlewareKeys($route) === []) {
            $missing[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    expect($missing)->toBe([])->and($seen)->toBeGreaterThanOrEqual(count(ShellNavigation::ADMIN_ITEMS));
});

it('resolves every route using the admin middleware to a permission mapping', function () {
    $unmapped = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        foreach (adminMiddlewareKeys($route) as $key) {
            $valid = $key === null
                ? ShellNavigation::hasRoute((string) $route->getName())
                : Permission::tryFrom($key) !== null;

            if (! $valid) {
                $unmapped[] = $route->methods()[0].' /'.$route->uri().' ('.($key ?? 'no key').')';
            }
        }
    }

    expect($unmapped)->toBe([]);
});

it('maps every admin.* page route in ShellNavigation::ADMIN_ITEMS, and every item to a route', function () {
    $named = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'admin.')) {
            $named[] = $route->getName();
        }
    }

    $mapped = array_column(array_values(ShellNavigation::ADMIN_ITEMS), 0);

    expect($named)->toEqualCanonicalizing($mapped)->and($named)->toHaveCount(count(ShellNavigation::ADMIN_ITEMS));
});

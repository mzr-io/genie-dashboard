<?php

use Illuminate\Support\Facades\Route;

// Story 1.25: the area (User or Admin) changes only at sign-in (the card chosen) and on a Workspace
// switch that downgrades it. No route switches the area on its own in Epic 1. When one is added this
// test fails until it has a CSRF and a session-rotation test in the security suite and the manifest
// (tests/Security/CoverageManifestTest.php) lists them.

/** Words that make a route look like a mode, role or area change. */
const AREA_WORDS = ['switch', 'area', 'mode', 'role', 'elevate'];

it('has no route other than the Workspace switch that switches, elevates or changes area, mode or role', function () {
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() === 'workspaces.switch') {
            continue;
        }

        $words = preg_split('/[^a-z0-9]+/', strtolower($route->uri().' '.$route->getName())) ?: [];

        if (array_intersect($words, AREA_WORDS) !== []) {
            $found[] = implode('|', $route->methods()).' '.$route->uri().' ['.$route->getName().']';
        }
    }

    expect($found)->toBe([], 'a route that may change the area exists: add CSRF and session-rotation tests and list them in the coverage manifest');
});

it('writes the session area only at sign-in and in the Workspace switch', function () {
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() === 'php' && preg_match("/->put\\(\\s*'area'/", (string) file_get_contents($file->getPathname())) === 1) {
            $writers[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'Modules/Identity/Application/SignIn.php',
        'Modules/Identity/Application/SwitchWorkspace.php',
    ], 'a new writer of the session area needs CSRF and rotation tests and a manifest entry');
});

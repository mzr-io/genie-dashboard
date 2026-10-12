<?php

use App\Http\Navigation\ShellNavigation;
use App\Models\User;
use App\Modules\Access\Contracts\Permission;
use Illuminate\Support\Facades\Route;

// Story 3.1 on the SQLite suite: the route's gate. The 200 and the audited denial run in the Database suite.
it('requires a signed-in user', function () {
    $this->getJson('/api/v1/block-types')->assertUnauthorized();
});

it('answers a User-area session with 403 access.not_authorized', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost:8000')
        ->getJson('/api/v1/block-types')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'access.not_authorized');
});

it('maps the route to blocks.edit behind the admin middleware', function () {
    $route = Route::getRoutes()->getByName('api.admin.block-types.index');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/v1/block-types')
        ->and($route->methods())->toContain('GET')
        ->and(ShellNavigation::ADMIN_API_ROUTES['api.admin.block-types.index'])->toBe(Permission::BlocksEdit);
});

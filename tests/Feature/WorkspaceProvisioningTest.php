<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => $this->withoutVite());

it('has no route that creates a Workspace: such requests return 404', function (string $method, string $uri) {
    $this->call($method, $uri, ['name' => 'Acme', 'label' => 'acme'])->assertNotFound();

    $this->actingAs(User::factory()->create())->call($method, $uri, ['name' => 'Acme'])->assertNotFound();
})->with([
    ['POST', '/workspaces'],
    ['POST', '/api/v1/workspaces'],
    ['POST', '/admin/workspaces'],
    ['POST', '/admin/workspaces/create'],
    ['PUT', '/workspaces'],
    ['GET', '/workspaces/create'],
    ['GET', '/register'],
    ['POST', '/register'],
]);

it('registers no route whose path creates a Workspace', function () {
    foreach (Route::getRoutes() as $route) {
        $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);

        // `workspaces/switch` (Story 1.17) changes the active Workspace of a session; it creates nothing.
        expect($writes !== [] && str_contains($route->uri(), 'workspace') && $route->uri() !== 'workspaces/switch')->toBeFalse("route {$route->uri()} must not exist");
    }
});

it('answers a malformed or unknown invitation link with the neutral 410 page', function (string $token) {
    $this->get("/invitations/{$token}")->assertStatus(410)
        ->assertInertia(fn ($page) => $page->component('auth/InvitationExpired'));

    $this->post("/invitations/{$token}", ['email' => 'a@example.test', 'name' => 'A', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])
        ->assertStatus(410);
})->with(['not-a-token', str_repeat('A', 43), str_repeat('A', 300)]);

it('refuses to create a Workspace while the invitation lifetime is unset', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => null]);

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']))->toBe(1)
        ->and(Artisan::output())->toContain('invitation lifetime is not set');
});

it('refuses an invalid email before touching any database', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => '24']);

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'nope']))->toBe(2);
});

it('treats an empty or non-numeric lifetime as unset', function (mixed $value) {
    config(['dashflow.tunables.users.invitation_lifetime.value' => $value]);

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']))->toBe(1);
})->with(['', '0', 'soon', '-5']);

it('sends Referrer-Policy and Cache-Control no-store on every invitation response, even a refusal', function () {
    foreach (['/invitations/expired', '/invitations/not-a-token'] as $uri) {
        $response = $this->get($uri);

        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('throttles the invitation routes and does not require a guest', function () {
    foreach (['invitations.expired', 'invitations.show', 'invitations.accept'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect($middleware)->toContain('throttle:30,1')->not->toContain('guest');
    }
});

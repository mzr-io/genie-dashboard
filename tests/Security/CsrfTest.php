<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Auth\Support\FakeSignInMemberships;

// Story 1.25: CSRF on the state-changing session routes. The framework skips the check while the
// application runs in the `testing` environment, so these tests switch the environment first (as
// SessionIdleTest does for the extend endpoint) and put it back afterwards.
beforeEach(function () {
    $this->originalEnv = app()['env'];
    $this->withoutVite();
    FakeSignInMemberships::install();
    RateLimiter::clear('x');
    app()['env'] = 'local';
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function csrfMember(): User
{
    return User::factory()->create();
}

it('refuses a sign-in post without a CSRF token with 419 and signs nobody in', function () {
    $user = csrfMember();

    $this->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/login', ['email' => $user->email, 'password' => 'password', 'role' => 'user'])
        ->assertStatus(419);

    $this->assertGuest();
});

it('lets a sign-in post that carries the session token reach the sign-in flow, not the throttle', function () {
    $this->withSession(['_token' => 'known-token'])
        ->postJson('/login', ['_token' => 'known-token', 'email' => 'nobody@example.test', 'password' => 'wrong-password', 'role' => 'user'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => $message !== null);

    // The same post without the token is the CSRF refusal, so the 419 above is about the token alone.
    $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password', 'role' => 'user'])
        ->assertStatus(419);
});

it('refuses a Workspace switch post without a CSRF token with 419', function () {
    $this->actingAs(csrfMember())->withSession(['workspace_id' => (string) Str::uuid7(), 'area' => 'user'])
        ->withHeader('Referer', 'http://localhost:8000')
        ->postJson(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()])
        ->assertStatus(419);
});

it('refuses a sign-out post without a CSRF token with 419 and keeps the session', function () {
    $this->actingAs(csrfMember())->withSession(['workspace_id' => (string) Str::uuid7(), 'area' => 'user'])
        ->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/logout')
        ->assertStatus(419);

    $this->assertAuthenticated();
});

it('refuses a password-reset submit without a CSRF token with 419', function () {
    $user = csrfMember();

    $this->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/reset-password', ['token' => 'x', 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertStatus(419);
});

it('refuses an invitation acceptance without a CSRF token with 419', function () {
    $this->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/invitations/'.Str::random(40), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertStatus(419);
});

it('refuses a profile update without a CSRF token with 419', function () {
    $user = csrfMember();

    $this->actingAs($user)->withSession(['workspace_id' => (string) Str::uuid7(), 'area' => 'user'])
        ->withHeader('Referer', 'http://localhost:8000')
        ->patchJson(route('profile.update'), ['name' => 'Changed'])
        ->assertStatus(419);

    expect($user->fresh()->name)->toBe($user->name);
});

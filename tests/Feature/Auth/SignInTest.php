<?php

use App\Models\User;
use App\Modules\Identity\Application\SignIn;
use App\Modules\Identity\Application\SignInLimits;
use App\Modules\Identity\Application\SignInRefused;
use App\Modules\Identity\Contracts\SignInArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Auth\Support\FakeSignInMemberships;

beforeEach(function () {
    $this->withoutVite();
    $this->memberships = FakeSignInMemberships::install();
});

function signInAs(User $user, array $override = [])
{
    return test()->postJson(route('login.store'), $override + ['email' => $user->email, 'password' => 'password', 'role' => 'user']);
}

it('renders the sign-in page with the reset link available', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Login')
        ->where('canResetPassword', true));
});

it('signs in a User, stores the Workspace and area in a rotated session and lands on overview', function () {
    $user = User::factory()->create();
    $membership = $this->memberships->give($user->id, 'user');

    $this->startSession();
    $before = session()->getId();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'user'])
        ->assertRedirect(route('overview', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session('workspace_id'))->toBe($membership->workspaceId)
        ->and(session('area'))->toBe('user')
        ->and(session()->getId())->not->toBe($before)
        ->and($this->memberships->marked)->toBe([$membership->membershipId]);
});

it('treats a missing role as the User area', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'admin');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('overview', absolute: false));
    expect(session('area'))->toBe('user');
});

it('signs in an Admin with an admin membership and lands on the Admin overview', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'admin');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'admin'])
        ->assertRedirect(route('admin.overview', absolute: false));

    expect(session('area'))->toBe('admin');
});

it('refuses the Admin card without an admin role: role-denied, no session, nothing stored', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');

    signInAs($user, ['role' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role' => 'signin-role-denied']);

    $this->assertGuest();
    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();
});

it('answers an unknown email, a wrong password and a user with no membership identically', function () {
    $known = User::factory()->create();
    $this->memberships->give($known->id, 'user');
    $orphan = User::factory()->create();

    $responses = [
        signInAs($known, ['password' => 'wrong']),
        test()->postJson(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'password', 'role' => 'user']),
        signInAs($orphan),
    ];

    foreach ($responses as $response) {
        $response->assertStatus(422)->assertExactJson([
            'message' => 'signin-failed',
            'errors' => ['email' => ['signin-failed']],
        ]);
    }

    $this->assertGuest();
});

it('matches the email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'mixed@example.test']);
    $this->memberships->give($user->id, 'user');

    signInAs($user, ['email' => 'MIXED@Example.test'])->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('does not sign in a person whose only memberships are not usable', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'admin', usable: false);

    signInAs($user)->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    $this->assertGuest();
});

it('lands in the last used Workspace', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user', 'Alpha', '2026-01-01 10:00:00');
    $latest = $this->memberships->give($user->id, 'user', 'Beta', '2026-03-01 10:00:00');
    $this->memberships->give($user->id, 'user', 'Gamma', null);

    signInAs($user)->assertOk();
    expect(session('workspace_id'))->toBe($latest->workspaceId);
});

it('lands in the first Workspace by name when none was used yet', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user', 'Beta');
    $first = $this->memberships->give($user->id, 'user', 'Alpha');

    signInAs($user)->assertOk();
    expect(session('workspace_id'))->toBe($first->workspaceId);
});

it('adds no remember cookie while the Remember-me maximum is unset', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');

    $response = signInAs($user, ['remember' => true])->assertOk();

    expect(collect($response->headers->getCookies())->map->getName()->filter(fn ($n) => str_starts_with($n, 'remember_')))->toBeEmpty();
});

it('extends the session with Remember me up to the configured maximum', function () {
    config(['dashflow.tunables.sessions.remember_me_duration.value' => '30']);
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');

    $response = signInAs($user, ['remember' => true])->assertOk();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime() - time())->toBeLessThanOrEqual(30 * 60)
        ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(29 * 60 - 5);
});

it('throttles with Fortify\'s 5 per minute while the limits are unset: 429 with the throttled key', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');

    expect(SignInLimits::maxAttempts())->toBe(5)->and(SignInLimits::decaySeconds())->toBe(60);

    foreach (range(1, 5) as $_) {
        signInAs($user, ['password' => 'wrong'])->assertStatus(422);
    }

    signInAs($user, ['password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'throttled')
        ->assertJsonPath('errors.email.0', 'throttled');
    signInAs($user)->assertStatus(429);
    $this->assertGuest();
});

it('throttles by the configured limits', function () {
    config([
        'dashflow.tunables.sessions.sign_in_max_attempts.value' => '2',
        'dashflow.tunables.sessions.sign_in_decay_seconds.value' => '120',
    ]);
    $user = User::factory()->create(['email' => 'limits@example.test']);
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower($user->email).'|127.0.0.1')));

    expect(SignInLimits::maxAttempts())->toBe(2)->and(SignInLimits::decaySeconds())->toBe(120);

    signInAs($user, ['password' => 'wrong'])->assertStatus(422);
    signInAs($user, ['password' => 'wrong'])->assertStatus(422);
    signInAs($user, ['password' => 'wrong'])->assertStatus(429);
});

it('ignores a limit that is not a positive whole number', function (mixed $value) {
    config(['dashflow.tunables.sessions.sign_in_max_attempts.value' => $value]);

    expect(SignInLimits::maxAttempts())->toBe(5);
})->with(['', '0', '-3', 'abc', '1.5', null]);

it('names the Sign in, Forgot password and Reset password routes and the Overview with its dashboard alias', function () {
    foreach (['login', 'password.request', 'password.reset', 'overview', 'dashboard', 'admin.overview', 'help'] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }

});

it('guards the Admin overview but leaves Help & support open to guests', function () {
    $this->get(route('admin.overview'))->assertRedirect(route('login'));
    $this->get(route('help'))->assertOk();
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();
});

it('exposes no register, two-factor, passkey or verification routes', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->implode("\n");

    expect($uris)->not->toMatch('/register|two-factor|passkey|email\/verif/i');
});

it('refuses a role other than user or admin as signin-failed, while a missing role means User', function (mixed $role) {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'admin');

    signInAs($user, ['role' => $role])->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    $this->assertGuest();
})->with(['superuser', 'ADMIN', 1]);

it('refuses oversized email and password before hashing', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');

    signInAs($user, ['email' => str_repeat('a', 250).'@x.test'])->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    signInAs($user, ['password' => str_repeat('p', 1025)])->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    $this->assertGuest();
});

it('refuses a request with no session and logs nobody in', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user');
    $request = Request::create('/login', 'POST', ['email' => $user->email, 'password' => 'password']);

    expect(fn () => app(SignIn::class)->handle($request, $user->email, 'password', SignInArea::User))
        ->toThrow(SignInRefused::class);
    $this->assertGuest();
});

it('lands an Admin card sign-in in the most recent usable admin Workspace', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id, 'user', 'Alpha', '2026-06-01 10:00:00');
    $admin = $this->memberships->give($user->id, 'admin', 'Beta', '2026-01-01 10:00:00');

    signInAs($user, ['role' => 'admin'])->assertOk();
    expect(session('workspace_id'))->toBe($admin->workspaceId)->and(session('area'))->toBe('admin');
});

it('forgets a previous person\'s Workspace and area when a different person signs in', function () {
    $user = User::factory()->create();
    $membership = $this->memberships->give($user->id, 'user');
    $this->startSession();
    session()->put(['workspace_id' => (string) Str::uuid7(), 'area' => 'admin']);

    // Not through the guest-only route: call the service with a stale session.
    app(SignIn::class)->handle(
        tap(Request::create('/login', 'POST'), fn ($r) => $r->setLaravelSession(session()->driver())),
        $user->email, 'password', SignInArea::User,
    );

    expect(session('workspace_id'))->toBe($membership->workspaceId)->and(session('area'))->toBe('user');
});

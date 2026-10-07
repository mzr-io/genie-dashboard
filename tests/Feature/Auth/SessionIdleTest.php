<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Identity\Application\IdleLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Auth\Support\FakeSignInMemberships;

beforeEach(function () {
    $this->withoutVite();
    config()->set('dashflow.tunables.sessions.idle_user.value', 10);
    config()->set('dashflow.tunables.sessions.idle_admin.value', 30);
    Carbon::setTestNow('2026-10-06 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function idleSession(int $secondsAgo, string $area = 'user'): array
{
    return ['area' => $area, 'last_user_activity' => now()->getTimestamp() - $secondsAgo];
}

it('uses the area tunable, else the starter kit session lifetime', function () {
    expect(IdleLimit::seconds('user'))->toBe(600)->and(IdleLimit::seconds('admin'))->toBe(1800);

    config()->set('dashflow.tunables.sessions.idle_user.value', null);
    config()->set('dashflow.tunables.sessions.idle_admin.value', null);
    config()->set('session.lifetime', 120);

    expect(IdleLimit::seconds('user'))->toBe(7200)->and(IdleLimit::seconds('admin'))->toBe(7200);
});

it('reports the seconds left and the area without extending', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(100));

    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000'])
        ->assertOk()
        ->assertExactJson(['remaining_seconds' => 500, 'area' => 'user']);

    expect(session('last_user_activity'))->toBe(now()->getTimestamp() - 100);
});

it('answers a guest status call with 401', function () {
    $this->getJson('/api/v1/session')->assertUnauthorized();
});

it('never extends the idle session on a background request or the status call', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(100));

    $this->get(route('overview'), ['X-Background' => '1'])->assertOk();
    expect(session('last_user_activity'))->toBe(now()->getTimestamp() - 100);

    $this->getJson('/api/v1/ping', ['X-Background' => '1', 'Referer' => 'http://localhost:8000'])->assertOk();
    expect(session('last_user_activity'))->toBe(now()->getTimestamp() - 100);

    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000']);
    expect(session('last_user_activity'))->toBe(now()->getTimestamp() - 100);
});

it('extends the idle session on a user-initiated request', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(100));

    $this->get(route('overview'))->assertOk();

    expect(session('last_user_activity'))->toBe(now()->getTimestamp());
});

it('extends through the extend endpoint and returns the new remaining seconds', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(540));

    $this->postJson('/api/v1/session/extend', [], ['Referer' => 'http://localhost:8000'])
        ->assertOk()
        ->assertExactJson(['remaining_seconds' => 600, 'area' => 'user']);

    expect(session('last_user_activity'))->toBe(now()->getTimestamp());
});

it('extends even when the call carries the background header, because it is the explicit extend', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(540));

    $this->postJson('/api/v1/session/extend', [], ['X-Background' => '1', 'Referer' => 'http://localhost:8000'])
        ->assertOk()->assertJson(['remaining_seconds' => 600]);
});

it('protects the extend endpoint with CSRF', function () {
    app()['env'] = 'local';

    $this->actingAs(User::factory()->create())->withSession(idleSession(10))
        ->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/api/v1/session/extend')
        ->assertStatus(419);
});

it('signs out an expired session: 401 for JSON, nothing left in the session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(idleSession(601) + ['workspace_id' => null]);

    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000/dashboard'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'platform.unauthenticated');

    $this->assertGuest();
    expect(session('area'))->toBeNull()->and(session('last_user_activity'))->toBeNull()
        ->and(session('url.intended'))->toBe('http://localhost:8000/dashboard');
});

it('answers an expired Inertia visit with 401 and remembers that page', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(601));

    $this->get('/dashboard?tab=2', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnauthorized();

    expect(session('url.intended'))->toEndWith('/dashboard?tab=2');
});

it('redirects an expired page load to sign-in and remembers the page', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(601));

    $this->get('/dashboard')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session('url.intended'))->toEndWith('/dashboard')->and(session('session_expired'))->toBeTrue();
});

it('ignores a cross-origin referer when remembering the page', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(601));

    $this->postJson('/logout', [], ['Referer' => 'https://evil.example/x'])->assertUnauthorized();

    expect(session('url.intended'))->toBe(route('overview'));
});

it('does not touch a guest request', function () {
    $this->get(route('login'))->assertOk();
    $this->assertGuest();
});

it('uses the Admin limit in the Admin area', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(700, 'admin'));

    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000'])
        ->assertOk()->assertJson(['remaining_seconds' => 1100, 'area' => 'admin']);
});

it('lands on the page the person was on, with the session-expired flash, after signing back in', function () {
    $memberships = FakeSignInMemberships::install();
    $user = User::factory()->create();
    $memberships->give($user->id, 'user');

    $this->actingAs($user)->withSession(idleSession(601));
    $this->get('/settings/profile')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'user'])
        ->assertRedirect(url('/settings/profile'));

    expect(session('session_expired'))->toBeNull()
        ->and(session('inertia.flash_data'))->toBe(['session_expired' => true])
        ->and(session('url.intended'))->toBeNull()
        ->and(session('last_user_activity'))->toBe(now()->getTimestamp());
});

it('lands on the area Overview when there is no intended URL, without the expiry flash', function () {
    $memberships = FakeSignInMemberships::install();
    $user = User::factory()->create();
    $memberships->give($user->id, 'user');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'user'])
        ->assertRedirect(route('overview', absolute: false));

    expect(session('inertia.flash_data'))->toBeNull();
});

it('lets the X-Background header reach the request and keeps the clock still', function () {
    Route::middleware('web')->get('__probe', fn () => response()->json(['bg' => request()->header('X-Background')]));
    $this->actingAs(User::factory()->create())->withSession(idleSession(100));

    $this->withHeaders(['X-Background' => '1'])->get('__probe')->assertJson(['bg' => '1']);
    expect(session('last_user_activity'))->toBe(now()->getTimestamp() - 100);
});

it('lets the same request without the header extend the clock', function () {
    Route::middleware('web')->get('__probe', fn () => response()->json(['bg' => request()->header('X-Background')]));
    $this->actingAs(User::factory()->create())->withSession(idleSession(100));

    $this->get('__probe')->assertJson(['bg' => null]);
    expect(session('last_user_activity'))->toBe(now()->getTimestamp());
});

it('caps the idle limit at the session lifetime', function () {
    config()->set('session.lifetime', 20);
    config()->set('dashflow.tunables.sessions.idle_user.value', 90);
    config()->set('dashflow.tunables.sessions.idle_admin.value', 15);

    expect(IdleLimit::seconds('user'))->toBe(1200)->and(IdleLimit::seconds('admin'))->toBe(900);
});

it('throttles the extend endpoint', function () {
    $this->actingAs(User::factory()->create())->withSession(idleSession(10));

    foreach (range(1, 30) as $_) {
        $this->postJson('/api/v1/session/extend', [], ['Referer' => 'http://localhost:8000'])->assertOk();
    }

    $this->postJson('/api/v1/session/extend', [], ['Referer' => 'http://localhost:8000'])->assertStatus(429);
});

it('remembers only pages of this application as the intended URL', function (string $referer) {
    $this->actingAs(User::factory()->create())->withSession(idleSession(601));

    $this->postJson('/logout', [], ['Referer' => $referer])->assertUnauthorized();

    expect(session('url.intended'))->toBe(route('overview'));
})->with([
    'another host' => 'https://evil.example/x',
    'another scheme' => 'https://localhost/dashboard',
    'another port' => 'http://localhost:9999/dashboard',
    'the sign-in page' => 'http://localhost:8000/login',
    'a sign-out URL' => 'http://localhost:8000/logout',
    'an API path' => 'http://localhost:8000/api/v1/ping',
    'credentials in the URL' => 'http://user:pw@localhost/dashboard',
]);

it('does not remember a JSON endpoint, a prefetch or a background call as the page', function (array $headers, string $path) {
    $this->actingAs(User::factory()->create())->withSession(idleSession(601));

    $version = ['X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];

    $this->get($path, $headers + $version + ['Referer' => 'http://localhost:8000/settings/profile'])->assertStatus(401);

    // The page that made the call, not the endpoint that was called.
    expect(session('url.intended'))->toBe('http://localhost:8000/settings/profile');
})->with([
    'JSON' => [['Accept' => 'application/json'], '/dashboard'],
    'prefetch' => [['X-Inertia' => 'true', 'Purpose' => 'prefetch', 'X-Requested-With' => 'XMLHttpRequest'], '/dashboard'],
    'background' => [['X-Background' => '1', 'X-Requested-With' => 'XMLHttpRequest'], '/dashboard'],
]);

it('returns to the intended page only inside the area just opened', function (string $role, string $intended, string $expected) {
    $memberships = FakeSignInMemberships::install();
    $user = User::factory()->create();
    $memberships->give($user->id, 'admin');

    $this->withSession(['url.intended' => $intended])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => $role])
        ->assertRedirect($expected);
})->with([
    'user to a user page' => ['user', 'http://localhost:8000/settings/profile', 'http://localhost:8000/settings/profile'],
    'user to an admin page' => ['user', 'http://localhost:8000/admin', 'http://localhost:8000/dashboard'],
    'admin to an admin page' => ['admin', 'http://localhost:8000/admin/users', 'http://localhost:8000/admin/users'],
    'admin to a user page' => ['admin', 'http://localhost:8000/dashboard', 'http://localhost:8000/admin'],
    'user to another origin' => ['user', 'https://evil.example/x', 'http://localhost:8000/dashboard'],
    'user to the API' => ['user', 'http://localhost:8000/api/v1/session', 'http://localhost:8000/dashboard'],
    'user to logout' => ['user', 'http://localhost:8000/logout', 'http://localhost:8000/dashboard'],
]);

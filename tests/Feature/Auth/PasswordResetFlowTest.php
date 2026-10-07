<?php

use App\Models\User;
use App\Modules\Identity\Application\PasswordResetRecorder;
use App\Modules\Identity\Application\ResetLimits;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Auth\Support\FakeSignInMemberships;

// Story 1.14: the non-enumerating reset request, the one-state expired link, other-session invalidation,
// the audit event and the tunables. The Database suite repeats the audit and session rows on PostgreSQL.
beforeEach(function () {
    $this->withoutVite();
    $this->memberships = FakeSignInMemberships::install();
    RateLimiter::clear('x');
    Notification::fake();
});

function resetToken(User $user): string
{
    return Password::broker()->createToken($user);
}

function putSession(string $id, ?int $userId): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => 'x', 'last_activity' => time()]);
}

it('answers a known and an unknown email identically and sends mail only for the known one', function () {
    $user = User::factory()->create();

    $known = $this->postJson(route('password.email'), ['email' => $user->email]);
    $unknown = $this->postJson(route('password.email'), ['email' => 'nobody@example.test']);

    $known->assertOk()->assertExactJson(['message' => 'reset-requested']);
    $unknown->assertOk()->assertExactJson(['message' => 'reset-requested']);
    expect($unknown->getContent())->toBe($known->getContent());
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

it('redirects both Inertia requests to the same place with the same status key', function () {
    $user = User::factory()->create();

    $a = $this->from('/forgot-password')->post(route('password.email'), ['email' => $user->email]);
    $b = $this->from('/forgot-password')->post(route('password.email'), ['email' => 'nobody@example.test']);

    $a->assertRedirect('/forgot-password')->assertSessionHas('status', 'reset-requested');
    $b->assertRedirect('/forgot-password')->assertSessionHas('status', 'reset-requested');
});

it('shows reset-requested on the forgot page after a request', function () {
    $this->withSession(['status' => 'reset-requested'])->get(route('password.request'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ForgotPassword')->where('status', 'reset-requested'));
});

it('answers a malformed email with the field-error key and sends nothing', function (string $email) {
    $this->postJson(route('password.email'), ['email' => $email])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email' => 'field-error']);

    Notification::assertNothingSent();
})->with(['abc', '', 'a@']);

it('still answers reset-requested when the mail fails, logging without the address', function () {
    $user = User::factory()->create(['email' => 'secret.person@example.test']);
    $logged = [];
    Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$logged) {
        $logged[] = [$message, $context];
    });
    Log::shouldReceive('warning', 'info', 'debug', 'notice')->andReturnNull();
    Password::shouldReceive('broker')->andReturn(new class implements PasswordBroker
    {
        public function sendResetLink(array $credentials, ?Closure $callback = null): never
        {
            throw new RuntimeException('SMTP refused secret.person@example.test');
        }

        public function reset(array $credentials, Closure $callback): never
        {
            throw new LogicException('not used');
        }
    });
    $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->assertExactJson(['message' => 'reset-requested']);

    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toBe(['identity.password_reset.mail_failed', ['exception' => RuntimeException::class]])
        ->and(json_encode($logged))->not->toContain('secret.person')
        ->and(json_encode($logged))->not->toContain('SMTP');
});

it('throttles requests per email and IP with HTTP 429 and the throttled key, known or not', function () {
    config(['dashflow.tunables.sessions.reset_request_max_attempts.value' => '2']);
    $user = User::factory()->create();

    foreach ([$user->email, 'nobody@example.test'] as $email) {
        $this->postJson(route('password.email'), ['email' => $email])->assertOk();
        $this->postJson(route('password.email'), ['email' => $email])->assertOk();
        $this->postJson(route('password.email'), ['email' => $email])
            ->assertStatus(429)
            ->assertJsonValidationErrors(['email' => 'throttled']);
    }
});

it('falls back to the starter kit limits while the tunables are unset', function () {
    expect(ResetLimits::maxAttempts())->toBe(5)
        ->and(ResetLimits::decaySeconds())->toBe(60)
        ->and(ResetLimits::lifetimeMinutes())->toBe(60)
        ->and(config('auth.passwords.users.expire'))->toBe(60);

    $this->postJson(route('password.email'), ['email' => 'a@example.test']);
    foreach (range(1, 4) as $_) {
        $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertOk();
    }
    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertStatus(429);
});

it('applies the configured link lifetime and ignores unusable values', function () {
    config(['dashflow.tunables.sessions.reset_link_lifetime.value' => '15']);
    ResetLimits::applyLifetime();
    expect(config('auth.passwords.users.expire'))->toBe(15);

    config(['dashflow.tunables.sessions.reset_link_lifetime.value' => 'soon', 'dashflow.tunables.sessions.reset_request_max_attempts.value' => '0']);
    expect(ResetLimits::lifetimeMinutes())->toBe(60)->and(ResetLimits::maxAttempts())->toBe(5);
});

it('renders the reset form for a valid link and the expired state for every other', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ResetPassword')->where('expired', false)->where('token', $token)->where('email', $user->email));

    $other = User::factory()->create();
    foreach ([
        ['token' => 'tampered', 'email' => $user->email],
        ['token' => $token, 'email' => $other->email],
        ['token' => $token, 'email' => 'nobody@example.test'],
        ['token' => $token, 'email' => ''],
    ] as $query) {
        $this->get(route('password.reset', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ResetPassword')->where('expired', true)->where('token', null)->where('email', null));
    }
});

it('treats a link past its lifetime as expired', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    $this->travel(61)->minutes();

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('expired', true));
    $this->postJson(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertStatus(422)->assertJsonValidationErrors(['email' => 'reset-expired']);
});

it('changes the password once, then reports the used link as expired', function () {
    $user = User::factory()->create();
    $token = resetToken($user);
    $payload = ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

    $this->post(route('password.update'), $payload)->assertRedirect(route('login'))->assertSessionHas('status', 'password-changed');
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();

    $this->postJson(route('password.update'), ['password' => 'another-password-9', 'password_confirmation' => 'another-password-9'] + $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['email' => 'reset-expired']);
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('shows one expired outcome for tampered tokens, wrong emails and malformed input, changing nothing', function () {
    $user = User::factory()->create();
    $hash = $user->password;
    $token = resetToken($user);

    foreach ([
        ['token' => 'nope', 'email' => $user->email],
        ['token' => $token, 'email' => 'nobody@example.test'],
        ['token' => $token, 'email' => User::factory()->create()->email],
        ['token' => $token, 'email' => ['x']],
        ['token' => '', 'email' => $user->email],
    ] as $input) {
        $this->from('/reset-password/x')->post(route('password.update'), $input + ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertRedirect('/reset-password/x')
            ->assertSessionHasErrors(['email' => 'reset-expired']);
    }

    expect($user->fresh()->password)->toBe($hash);
});

it('keeps the link valid when the new password fails the rules', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    $this->postJson(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
    $this->postJson(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'different-123'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);

    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));
});

it('deletes every other session of the user and keeps other users sessions', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    putSession('other-1', $user->id);
    putSession('other-2', $user->id);
    putSession('bystander', $other->id);
    $token = resetToken($user);

    $this->startSession();
    $before = session()->getId();
    putSession($before, $user->id);

    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'bystander')->exists())->toBeTrue()
        ->and(session()->getId())->not->toBe($before);
});

it('logs the reason only when the user has no Workspace', function () {
    $user = User::factory()->create(['email' => 'lonely.person@example.test']);
    $lines = [];
    Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$lines) {
        $lines[] = [$message, $context];
    });

    app(PasswordResetRecorder::class)->reset($user);

    expect($lines)->toBe([['identity.password.reset', ['reason' => 'no_membership']]]);
});

it('does not turn a failed audit write into an error', function () {
    $user = User::factory()->create();
    $this->memberships->give($user->id);
    // The SQLite suite has no audit table or RLS role: the real write fails and must stay invisible.
    $token = resetToken($user);

    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));
});

it('shows password-changed on the sign-in page', function () {
    $this->withSession(['status' => 'password-changed'])->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('status', 'password-changed'));
});

it('keeps the named routes', function () {
    foreach (['password.request', 'password.email', 'password.reset', 'password.update'] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('sends no-referrer and no-store headers on the reset pages and answers', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    foreach ([
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email])),
        $this->get(route('password.request')),
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short']),
        $this->postJson(route('password.email'), ['email' => 'abc']),
        $this->post(route('password.email'), ['email' => $user->email]),
    ] as $response) {
        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('matches a mixed-case stored email on request, view and reset', function () {
    $user = User::factory()->create(['email' => 'Mixed.Case@Example.test']);

    $this->postJson(route('password.email'), ['email' => 'mixed.case@example.test'])->assertOk();
    Notification::assertSentTo($user, ResetPassword::class);

    $token = resetToken($user);
    $this->get(route('password.reset', ['token' => $token, 'email' => 'MIXED.case@example.test']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('expired', false));
    $this->post(route('password.update'), ['token' => $token, 'email' => 'mixed.CASE@example.test', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('honours the link lifetime through the real path even after the broker was resolved', function () {
    $user = User::factory()->create();
    $token = resetToken($user); // resolves the broker with the starter kit 60 minutes
    config(['dashflow.tunables.sessions.reset_link_lifetime.value' => '15']);
    $query = ['token' => $token, 'email' => $user->email];
    $payload = $query + ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

    $this->travel(14)->minutes();
    $this->get(route('password.reset', $query))->assertInertia(fn (AssertableInertia $page) => $page->where('expired', false));

    $this->travel(2)->minutes(); // 16 minutes
    $this->get(route('password.reset', $query))->assertInertia(fn (AssertableInertia $page) => $page->where('expired', true));
    $this->postJson(route('password.update'), $payload)->assertStatus(422)->assertJsonValidationErrors(['email' => 'reset-expired']);
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeFalse();
});

it('accepts a link at 14 minutes through update under a 15 minute lifetime', function () {
    $user = User::factory()->create();
    $token = resetToken($user);
    config(['dashflow.tunables.sessions.reset_link_lifetime.value' => '15']);

    $this->travel(14)->minutes();
    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));
});

it('resets the throttle count after the configured decay', function () {
    config(['dashflow.tunables.sessions.reset_request_max_attempts.value' => '1', 'dashflow.tunables.sessions.reset_request_decay_seconds.value' => '30']);

    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertOk();
    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertStatus(429);

    $this->travel(29)->seconds();
    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertStatus(429);

    $this->travel(2)->seconds();
    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertOk();
});

it('does not throttle the same email from a different IP', function () {
    config(['dashflow.tunables.sessions.reset_request_max_attempts.value' => '1']);

    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertOk();
    $this->postJson(route('password.email'), ['email' => 'a@example.test'])->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson(route('password.email'), ['email' => 'a@example.test'])->assertOk();
});

it('destroys the old row of the current session when it is regenerated', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $token = resetToken($user);
    $old = Str::random(40);
    putSession($old, null);

    $this->withCookie(config('session.cookie'), $old)
        ->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('id', $old)->exists())->toBeFalse();
});

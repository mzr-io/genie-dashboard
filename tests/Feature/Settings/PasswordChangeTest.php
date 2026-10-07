<?php

use App\Models\User;
use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Audit\Audit;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Auth\Support\FakeSignInMemberships;

// Story 1.18: the Change password section of Profile & settings. The audit row is proven in the Database suite.
beforeEach(function () {
    $this->withoutVite();
    Log::spy();
    $this->memberships = FakeSignInMemberships::install();
});

function changePassword(User $user, array $override = [])
{
    return test()->actingAs($user)->from(route('profile.edit'))->put(route('user-password.update'), $override + [
        'current_password' => 'password',
        'password' => 'a-new-password-123',
        'password_confirmation' => 'a-new-password-123',
    ]);
}

function sessionRow(string $id, ?int $userId): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => 'x', 'last_activity' => time()]);
}

it('changes the password, deletes every other session and keeps the person signed in', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    sessionRow('laptop', $user->id);
    sessionRow('phone', $user->id);
    sessionRow('bystander', $other->id);
    $old = Str::random(40);
    sessionRow($old, $user->id);

    $this->actingAs($user)->withCookie(config('session.cookie'), $old)
        ->from(route('profile.edit'))
        ->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'a-new-password-123', 'password_confirmation' => 'a-new-password-123'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect(Hash::check('a-new-password-123', $user->refresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'laptop')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'phone')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'bystander')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', $old)->exists())->toBeFalse();

    $this->assertAuthenticatedAs($user);
    $this->get(route('profile.edit'))->assertOk();
});

it('rotates the remember token so a remember cookie on another device stops working', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);

    changePassword($user)->assertSessionHasNoErrors();

    expect($user->refresh()->remember_token)->not->toBe('old-token');
});

it('requires the current password: a wrong or missing one is a field error and nothing changes', function (array $override) {
    $user = User::factory()->create();
    sessionRow('laptop', $user->id);
    $hash = $user->password;

    changePassword($user, $override)->assertSessionHasErrors('current_password');

    expect($user->refresh()->password)->toBe($hash)
        ->and(DB::table('sessions')->where('id', 'laptop')->exists())->toBeTrue();
})->with([
    'wrong' => [['current_password' => 'not-my-password']],
    'missing' => [['current_password' => '']],
]);

it('applies Password::defaults() and the confirmation', function (array $override, string $field) {
    $user = User::factory()->create();
    $hash = $user->password;

    changePassword($user, $override)->assertSessionHasErrors($field);

    expect($user->refresh()->password)->toBe($hash);
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatch' => [['password_confirmation' => 'different-password-1'], 'password'],
]);

it('logs the reason only when the person has no Workspace to hold the security event', function () {
    $user = User::factory()->create();

    changePassword($user)->assertSessionHasNoErrors();

    Log::shouldHaveReceived('warning')->with('identity.password.changed', ['reason' => 'no_membership'])->once();
});

it('is throttled', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $_) {
        changePassword($user, ['current_password' => 'wrong']);
    }

    changePassword($user)->assertStatus(429);
});

it('no longer has a separate Security page', function () {
    $this->actingAs(User::factory()->create())->get('/settings/security')->assertNotFound();
});

it('revokes every API token of the person and no one else\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->createToken('cli');
    $user->createToken('mobile');
    $other->createToken('theirs');

    changePassword($user)->assertSessionHasNoErrors();

    expect($user->tokens()->count())->toBe(0)->and($other->tokens()->count())->toBe(1);
});

it('keeps tokens and the password when the change fails, and answers with an inline failure instead of a 500', function () {
    $user = User::factory()->create();
    $user->createToken('cli');
    $hash = $user->password;
    // Fail inside the transaction, after the password was written.
    User::updated(fn () => throw new RuntimeException('disk full'));

    changePassword($user)->assertRedirect(route('profile.edit'))->assertSessionHasErrors('form');

    expect($user->refresh()->password)->toBe($hash)->and($user->tokens()->count())->toBe(1);
    Log::shouldHaveReceived('error')->with('identity.password.change_failed', ['exception' => RuntimeException::class])->once();
});

it('still succeeds, and logs, when recording the event fails after the commit', function () {
    $user = User::factory()->create();
    app()->instance(SignInMemberships::class, new class implements SignInMemberships
    {
        public function forUser(int $userId): array
        {
            throw new RuntimeException('lookup down');
        }

        public function markActive(string $workspaceId, string $membershipId): bool
        {
            return true;
        }
    });

    changePassword($user)->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    expect(Hash::check('a-new-password-123', $user->refresh()->password))->toBeTrue();
    Log::shouldHaveReceived('error')->with('identity.password.changed.record_failed', ['action' => 'identity.password.changed'])->once();
});

it('logs a session regeneration failure and still succeeds', function () {
    $user = User::factory()->create();
    $request = Request::create('/x', 'PUT');
    $session = Mockery::mock(Session::class);
    $session->shouldReceive('getId')->andReturn('abc');
    $session->shouldReceive('regenerate')->andThrow(new RuntimeException('store down'));
    $request->setLaravelSession($session);

    app(ChangePassword::class)->handle($request, $user, 'a-new-password-123');

    expect(Hash::check('a-new-password-123', $user->refresh()->password))->toBeTrue();
    Log::shouldHaveReceived('error')->with('identity.password.change.session_failed', ['exception' => RuntimeException::class])->once();
});

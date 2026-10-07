<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// The Story 1.14 reset flow against the real PostgreSQL: the SECURITY DEFINER membership lookup, the
// `identity.password.reset` security event on its own connection, and the DELETE the `app` role holds on
// `sessions`.
beforeEach(fn () => $this->withoutVite());

function resetUser(string $email, ?string $workspaceId = null): int
{
    $id = Cluster::user($email);

    if ($workspaceId !== null) {
        Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $id, 'user', 'active']);
    }

    return $id;
}

function resetTokenFor(string $email): string
{
    return Password::broker()->createToken(User::query()->where('email', $email)->firstOrFail());
}

function resetSessionRow(string $id, ?int $userId): void
{
    Cluster::superuser()->prepare('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)')->execute([$id, $userId, 'x', time()]);
}

function resetPayload(string $email, string $token, string $password = 'a-brand-new-password'): array
{
    return ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
}

it('resets the password, deletes the other sessions as role app and records identity.password.reset in the Workspace', function () {
    $workspace = Cluster::workspace('Acme');
    $user = resetUser('ada@example.test', $workspace);
    $other = resetUser('bob@example.test', $workspace);
    resetSessionRow('ada-laptop', $user);
    resetSessionRow('ada-phone', $user);
    resetSessionRow('bob-laptop', $other);
    $token = resetTokenFor('ada@example.test');

    $this->post('/reset-password', resetPayload('ada@example.test', $token))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'password-changed');

    $hash = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];
    expect(Hash::check('a-brand-new-password', $hash))->toBeTrue();

    $sessions = array_column(Cluster::rows(Cluster::superuser(), 'select id from sessions'), 'id');
    expect($sessions)->toBe(['bob-laptop']);

    $events = Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ?', ['identity.password.reset']);
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and($events[0]['security'])->toBeTrue()
        ->and(json_decode($events[0]['after_state'], true))->toEqualCanonicalizing(['user_id' => $user, 'membership_id' => Cluster::rows(Cluster::superuser(), 'select id from workspace_memberships where user_id = ?', [$user])[0]['id']]);

    $row = json_encode($events[0]);
    expect($row)->not->toContain('ada@example.test')->not->toContain($token)->not->toContain('a-brand-new-password');
});

it('logs the reason only, and writes no audit row, for a user with no Workspace', function () {
    resetUser('lonely@example.test');
    $token = resetTokenFor('lonely@example.test');
    Log::spy();

    $this->post('/reset-password', resetPayload('lonely@example.test', $token))->assertRedirect(route('login'));

    expect(Cluster::rows(Cluster::superuser(), 'select id from audit_events'))->toBe([]);
    Log::shouldHaveReceived('warning')->with('identity.password.reset', ['reason' => 'no_membership'])->once();
});

it('leaves password, sessions and audit untouched for an expired or reused link', function () {
    $workspace = Cluster::workspace('Acme');
    $user = resetUser('ada@example.test', $workspace);
    resetSessionRow('ada-laptop', $user);
    $before = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];
    $token = resetTokenFor('ada@example.test');

    $this->travel(61)->minutes();
    $this->postJson('/reset-password', resetPayload('ada@example.test', $token))
        ->assertStatus(422)->assertJsonValidationErrors(['email' => 'reset-expired']);
    $this->travelBack();

    expect(Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'])->toBe($before)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sessions'))->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from audit_events'))->toBe([]);
});

it('keeps the token valid after a weak password and sessions untouched', function () {
    $workspace = Cluster::workspace('Acme');
    $user = resetUser('ada@example.test', $workspace);
    resetSessionRow('ada-laptop', $user);
    $token = resetTokenFor('ada@example.test');

    $this->postJson('/reset-password', resetPayload('ada@example.test', $token, 'short'))
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
    expect(Cluster::rows(Cluster::superuser(), 'select id from sessions'))->toHaveCount(1);

    $this->post('/reset-password', resetPayload('ada@example.test', $token))->assertRedirect(route('login'));
});

it('answers the reset request identically for known and unknown emails and sends one mail', function () {
    Notification::fake();
    $workspace = Cluster::workspace('Acme');
    resetUser('ada@example.test', $workspace);

    $known = $this->postJson('/forgot-password', ['email' => 'ada@example.test']);
    $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.test']);

    expect($known->status())->toBe(200)->and($known->getContent())->toBe($unknown->getContent());
    Notification::assertCount(1);
    Notification::assertSentTo(User::query()->where('email', 'ada@example.test')->first(), ResetPassword::class);
});

it('consumes the token with the password: a second use of the same token fails', function () {
    $workspace = Cluster::workspace('Acme');
    resetUser('ada@example.test', $workspace);
    $token = resetTokenFor('ada@example.test');

    $this->post('/reset-password', resetPayload('ada@example.test', $token))->assertRedirect(route('login'));
    expect(Cluster::rows(Cluster::superuser(), 'select email from password_reset_tokens'))->toBe([]);

    $this->postJson('/reset-password', resetPayload('ada@example.test', $token, 'yet-another-password'))
        ->assertStatus(422)->assertJsonValidationErrors(['email' => 'reset-expired']);

    $hash = Cluster::rows(Cluster::superuser(), 'select password from users where email = ?', ['ada@example.test'])[0]['password'];
    expect(Hash::check('a-brand-new-password', $hash))->toBeTrue();
});

it('rolls the password, sessions and token back when a step after the password write fails', function () {
    $workspace = Cluster::workspace('Acme');
    $user = resetUser('ada@example.test', $workspace);
    resetSessionRow('ada-laptop', $user);
    $before = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];
    $token = resetTokenFor('ada@example.test');
    $fail = true;
    Event::listen(PasswordReset::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('forced');
        }
    });

    $this->postJson('/reset-password', resetPayload('ada@example.test', $token))->assertStatus(500);

    expect(Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'])->toBe($before)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sessions'))->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select email from password_reset_tokens'))->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from audit_events'))->toBe([]);

    $fail = false;
    $this->post('/reset-password', resetPayload('ada@example.test', $token))->assertRedirect(route('login'));
});

it('finds a mixed-case stored email on request, view and reset', function () {
    Notification::fake();
    $workspace = Cluster::workspace('Acme');
    $user = resetUser('Mixed.Case@Example.test', $workspace);

    $this->postJson('/forgot-password', ['email' => 'mixed.case@example.test'])->assertOk();
    Notification::assertCount(1);

    $token = resetTokenFor('Mixed.Case@Example.test');
    $this->get('/reset-password/'.$token.'?email=MIXED.CASE@example.test')
        ->assertInertia(fn ($page) => $page->where('expired', false));
    $this->post('/reset-password', resetPayload('mixed.case@EXAMPLE.test', $token))->assertRedirect(route('login'));

    $hash = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];
    expect(Hash::check('a-brand-new-password', $hash))->toBeTrue()
        ->and(Cluster::rows(Cluster::superuser(), 'select action from audit_events'))->toHaveCount(1);
});

<?php

use App\Modules\Identity\Application\SignIn;
use App\Modules\Identity\Contracts\SignInArea;
use App\Platform\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// The Story 1.13 sign-in flow against the real PostgreSQL: the SECURITY DEFINER lookup, row-level security
// on the membership stamp, and security audit events on their own connection.
beforeEach(fn () => $this->withoutVite());

const SIGNIN_PASSWORD = 'a-long-enough-password';

function signinUser(string $email): int
{
    $id = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(SIGNIN_PASSWORD), $id]);

    return $id;
}

function signinMember(string $workspaceId, int $userId, string $role = 'user', string $status = 'active', ?string $lastActiveAt = null): string
{
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, last_active_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, now(), now())')
        ->execute([$id, $workspaceId, $userId, $role, $status, $lastActiveAt]);

    return $id;
}

/** @return list<array<string, mixed>> */
function signinAudit(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events'.($action === null ? '' : ' where action = ?').' order by occurred_at', $action === null ? [] : [$action]);
}

function signinPost(string $email, string $role = 'user', string $password = SIGNIN_PASSWORD, array $extra = [])
{
    return test()->postJson('/login', ['email' => $email, 'password' => $password, 'role' => $role] + $extra);
}

it('signs a User in: rotated session, Workspace and area stored, membership stamped, event recorded, overview reachable', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('ada@example.test');
    $membership = signinMember($workspace, $user);

    $this->startSession();
    $before = session()->getId();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => SIGNIN_PASSWORD, 'role' => 'user'])
        ->assertRedirect(route('overview', absolute: false));

    expect(session()->getId())->not->toBe($before)
        ->and(session('workspace_id'))->toBe($workspace)
        ->and(session('area'))->toBe('user');
    $this->assertAuthenticated();

    $row = Cluster::rows(Cluster::superuser(), 'select last_active_at from workspace_memberships where id = ?', [$membership])[0];
    expect($row['last_active_at'])->not->toBeNull();

    $events = signinAudit('identity.signin.succeeded');
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and($events[0]['security'])->toBeTrue()
        ->and($events[0]['actor'])->toBe($membership)
        ->and(json_decode($events[0]['after_state'], true))->toEqualCanonicalizing(['user_id' => $user, 'membership_id' => $membership, 'area' => 'user']);

    // The next request runs inside the Workspace transaction the session names.
    $this->get(route('overview'))->assertOk();
    expect(app(WorkspaceContext::class)->workspaceId())->toBeNull();
});

it('signs an Admin in to the Admin overview with area admin', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('root@example.test');
    signinMember($workspace, $user, 'admin');

    $this->post('/login', ['email' => 'root@example.test', 'password' => SIGNIN_PASSWORD, 'role' => 'admin'])
        ->assertRedirect(route('admin.overview', absolute: false));

    expect(session('area'))->toBe('admin')->and(session('workspace_id'))->toBe($workspace);
    $this->get(route('admin.overview'))->assertOk();
});

it('refuses the Admin card without the admin role: no session, identity.area.denied recorded', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('plain@example.test');
    signinMember($workspace, $user, 'user');

    signinPost('plain@example.test', 'admin')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role' => 'signin-role-denied']);

    $this->assertGuest();
    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();

    $events = signinAudit();
    expect($events)->toHaveCount(1)
        ->and($events[0]['action'])->toBe('identity.area.denied')
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and(json_decode($events[0]['after_state'], true)['area'])->toBe('admin');

    // The User card the message offers works.
    signinPost('plain@example.test', 'user')->assertOk();
});

it('answers an unknown email and a wrong password identically; only the known one has an audit row', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('known@example.test');
    signinMember($workspace, $user);

    Log::spy();
    $wrong = signinPost('known@example.test', 'user', 'not-the-password');
    $unknown = signinPost('nobody@example.test');

    expect($wrong->status())->toBe(422)->and($unknown->status())->toBe(422)
        ->and($wrong->getContent())->toBe($unknown->getContent());
    expect(json_decode($wrong->getContent(), true)['errors'])->toBe(['email' => ['signin-failed']]);
    $this->assertGuest();

    $events = signinAudit('identity.signin.failed');
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and(json_decode($events[0]['after_state'], true))->toEqualCanonicalizing(['user_id' => $user, 'reason' => 'bad_password']);
    Log::shouldHaveReceived('warning')->with('identity.signin.failed', ['reason' => 'unknown_user'])->once();
});

it('gives a valid user with no usable membership the neutral failure and a log line, never a session', function () {
    $workspace = Cluster::workspace('Acme');
    signinUser('orphan@example.test');
    $suspended = signinUser('suspended@example.test');
    signinMember($workspace, $suspended, 'admin', 'suspended');

    Log::spy();
    foreach (['orphan@example.test', 'suspended@example.test'] as $email) {
        signinPost($email)->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    }

    $this->assertGuest();
    expect(signinAudit())->toBe([]);
    Log::shouldHaveReceived('warning')->with('identity.signin.failed', ['reason' => 'no_membership'])->twice();
});

it('lands in the last used Workspace, else the first by name, and stamps only that membership', function () {
    $alpha = Cluster::workspace('Alpha');
    $beta = Cluster::workspace('Beta');
    $gamma = Cluster::workspace('Gamma');

    $fresh = signinUser('fresh@example.test');
    $freshAlpha = signinMember($alpha, $fresh);
    signinMember($beta, $fresh);

    $returning = signinUser('returning@example.test');
    signinMember($alpha, $returning, lastActiveAt: '2026-01-01 10:00:00');
    $returningGamma = signinMember($gamma, $returning, lastActiveAt: '2026-05-01 10:00:00');
    signinMember($beta, $returning, lastActiveAt: '2026-03-01 10:00:00');

    signinPost('fresh@example.test')->assertOk();
    expect(session('workspace_id'))->toBe($alpha);

    auth()->logout();
    $this->flushSession();
    signinPost('returning@example.test')->assertOk();
    expect(session('workspace_id'))->toBe($gamma);

    $stamped = Cluster::rows(Cluster::superuser(), 'select id from workspace_memberships where last_active_at > ? order by id', ['2026-06-01']);
    expect(array_column($stamped, 'id'))->toEqualCanonicalizing([$freshAlpha, $returningGamma]);
});

it('throttles with 429, the throttled key and identity.signin.throttled in the person\'s Workspace', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('busy@example.test');
    signinMember($workspace, $user);

    foreach (range(1, 5) as $_) {
        signinPost('busy@example.test', 'user', 'wrong-password')->assertStatus(422);
    }

    signinPost('busy@example.test', 'user', 'wrong-password')
        ->assertStatus(429)
        ->assertJsonPath('errors.email.0', 'throttled');
    signinPost('busy@example.test')->assertStatus(429);
    $this->assertGuest();

    // However long the flood lasts, the locked bucket writes one row per decay window.
    foreach (range(1, 5) as $_) {
        signinPost('busy@example.test')->assertStatus(429);
    }

    expect(signinAudit('identity.signin.throttled'))->toHaveCount(1)
        ->and(signinAudit('identity.signin.failed'))->toHaveCount(5);
});

it('logs a throttled attempt for an unknown email with the reason only', function () {
    config(['dashflow.tunables.sessions.sign_in_max_attempts.value' => '1']);

    Log::spy();
    signinPost('ghost@example.test')->assertStatus(422);
    signinPost('ghost@example.test')->assertStatus(429);

    expect(signinAudit())->toBe([]);
    Log::shouldHaveReceived('warning')->with('identity.signin.throttled', ['reason' => 'throttled'])->once();
});

it('puts no password, raw email or token in any audit row or log line', function () {
    $workspace = Cluster::workspace('Acme');
    $user = signinUser('secret.person@example.test');
    signinMember($workspace, $user, 'user');

    Log::spy();
    signinPost('secret.person@example.test', 'admin')->assertStatus(422);
    signinPost('secret.person@example.test', 'user', 'a-wrong-password-value')->assertStatus(422);
    signinPost('nobody.here@example.test', 'user', 'another-wrong-password')->assertStatus(422);
    signinPost('secret.person@example.test')->assertOk();

    $dump = json_encode(Cluster::rows(Cluster::superuser(), 'select * from audit_events'), JSON_THROW_ON_ERROR);
    foreach ([SIGNIN_PASSWORD, 'a-wrong-password-value', 'secret.person', 'example.test'] as $secret) {
        expect($dump)->not->toContain($secret);
    }

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        expect(json_encode($context))->not->toContain('example.test')->not->toContain('password');

        return true;
    });
});

it('clears the throttle bucket after a successful sign-in', function () {
    $workspace = Cluster::workspace('Acme');
    signinMember($workspace, signinUser('clear@example.test'));

    foreach (range(1, 4) as $_) {
        signinPost('clear@example.test', 'user', 'wrong-password')->assertStatus(422);
    }
    signinPost('clear@example.test')->assertOk();
    auth()->logout();

    // A fresh bucket: four more failures are still answered 422, not 429.
    foreach (range(1, 4) as $_) {
        signinPost('clear@example.test', 'user', 'wrong-password')->assertStatus(422);
    }
});

it('does not use a Workspace that is not active even with an active membership', function () {
    $suspended = Cluster::workspace('Acme', 'suspended');
    signinMember($suspended, signinUser('closed@example.test'), 'admin');

    Log::spy();
    signinPost('closed@example.test')->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);

    $this->assertGuest();
    expect(session('workspace_id'))->toBeNull();
    Log::shouldHaveReceived('warning')->with('identity.signin.failed', ['reason' => 'no_membership'])->once();
});

it('skips a later-used suspended Workspace and lands in the earlier active one', function () {
    $alpha = Cluster::workspace('Alpha');
    $beta = Cluster::workspace('Beta', 'suspended');
    $user = signinUser('skip@example.test');
    signinMember($alpha, $user, lastActiveAt: '2026-01-01 10:00:00');
    signinMember($beta, $user, lastActiveAt: '2026-06-01 10:00:00');

    signinPost('skip@example.test')->assertOk();
    expect(session('workspace_id'))->toBe($alpha);
});

it('lands the Admin card in the most recent usable admin Workspace, and refuses it only with none', function () {
    $alpha = Cluster::workspace('Alpha');
    $beta = Cluster::workspace('Beta');
    $gamma = Cluster::workspace('Gamma', 'suspended');
    $user = signinUser('mixed@example.test');
    signinMember($alpha, $user, 'user', lastActiveAt: '2026-06-01 10:00:00');
    signinMember($beta, $user, 'admin', lastActiveAt: '2026-01-01 10:00:00');
    signinMember($gamma, $user, 'admin', lastActiveAt: '2026-07-01 10:00:00');

    signinPost('mixed@example.test', 'admin')->assertOk();
    expect(session('workspace_id'))->toBe($beta)->and(session('area'))->toBe('admin');
    auth()->logout();

    $plain = signinUser('plain2@example.test');
    signinMember($alpha, $plain, 'user');
    signinPost('plain2@example.test', 'admin')->assertStatus(422)->assertJsonValidationErrors(['role' => 'signin-role-denied']);
});

it('refuses an invalid role value, an oversized email and an oversized password without a session', function () {
    signinMember(Cluster::workspace('Acme'), signinUser('limits@example.test'), 'admin');

    foreach ([
        signinPost('limits@example.test', 'root'),
        signinPost(str_repeat('a', 255).'@example.test'),
        signinPost('limits@example.test', 'user', str_repeat('p', 1025)),
    ] as $response) {
        $response->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    }

    $this->assertGuest();
});

it('keeps no Workspace or area of a previous session when someone signs in', function () {
    $workspace = Cluster::workspace('Acme');
    signinMember($workspace, signinUser('fresh2@example.test'));

    $this->startSession();
    session()->put(['workspace_id' => (string) Str::uuid7(), 'area' => 'admin']);
    // The guest-only route would redirect; sign in through the service with the stale session.
    $request = tap(Request::create('/login', 'POST'), fn ($r) => $r->setLaravelSession(session()->driver()));
    app(SignIn::class)->handle($request, 'fresh2@example.test', SIGNIN_PASSWORD, SignInArea::User);

    expect(session('workspace_id'))->toBe($workspace)->and(session('area'))->toBe('user');
});

it('lets a guest open Help & support', function () {
    $this->get(route('help'))->assertOk();
});

it('has the functional lower(email) index on users', function () {
    $rows = Cluster::rows(Cluster::superuser(), "select indexdef from pg_indexes where tablename = 'users' and indexname = 'users_email_lower_index'");

    expect($rows)->toHaveCount(1)->and($rows[0]['indexdef'])->toContain('lower');
});

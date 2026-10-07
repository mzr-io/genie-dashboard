<?php

use App\Models\User;
use App\Modules\Access\Contracts\Permission;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Database\Support\Cluster;

const PW = 'a-long-enough-password';

// The built Vite manifest does not exist in a clean checkout; the pages are asserted by component name.
beforeEach(fn () => $this->withoutVite());

/** @return array{0: string, 1: string} Workspace ID and the plain token from the sent email */
function provisionWorkspace(string $email = 'First.Admin@Example.test', string $name = 'Acme', string $label = 'acme'): array
{
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);

    $code = Artisan::call('dashflow:workspace:create', ['name' => $name, 'label' => $label, 'admin-email' => $email]);
    expect($code)->toBe(0, Artisan::output());

    $workspace = Cluster::rows(Cluster::superuser(), 'select id from workspaces where label = ?', [$label])[0]['id'];

    return [$workspace, sentToken()];
}

function sentToken(): string
{
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    $message = $messages->last()->getOriginalMessage();
    assert($message instanceof Email);

    preg_match('#/invitations/([A-Za-z0-9_-]{43})#', (string) $message->getTextBody(), $match);
    expect($match)->toHaveCount(2);

    return $match[1];
}

function acceptPayload(array $override = []): array
{
    return $override + ['email' => 'first.admin@example.test', 'name' => 'First Admin', 'password' => PW, 'password_confirmation' => PW];
}

function count_of(string $table): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
}

it('creates the Workspace, a hashed invitation, the email, the operator_audit row and the mirrored Workspace audit event', function () {
    [$workspace, $token] = provisionWorkspace();

    $row = Cluster::rows(Cluster::superuser(), 'select * from workspaces')[0];
    expect($row['id'])->toBe($workspace)->and($row['name'])->toBe('Acme')->and($row['label'])->toBe('acme');

    $invitation = Cluster::rows(Cluster::superuser(), 'select * from invitations')[0];
    expect($invitation['workspace_id'])->toBe($workspace)
        ->and($invitation['email'])->toBe('first.admin@example.test')
        ->and($invitation['role'])->toBe('admin')
        ->and($invitation['token_hash'])->toBe(hash('sha256', $token))
        ->and($invitation['used_at'])->toBeNull()
        ->and(strlen($token))->toBe(43)
        ->and(strtotime($invitation['expires_at']))->toBeGreaterThan(time() + 47 * 3600)
        ->and(strtotime($invitation['expires_at']))->toBeLessThan(time() + 49 * 3600);

    // The plain token is stored nowhere.
    foreach (['invitations', 'operator_audit', 'audit_events', 'workspaces'] as $table) {
        expect(Cluster::rows(Cluster::superuser(), "select t::text as r from {$table} t")[0]['r'] ?? '')->not->toContain($token);
    }

    $operator = Cluster::rows(Cluster::superuser(), 'select * from operator_audit')[0];
    expect($operator['action'])->toBe('platform.workspace.created')
        ->and($operator['workspace_id'])->toBe($workspace);

    $mirror = Cluster::rows(Cluster::superuser(), 'select * from audit_events')[0];
    expect($mirror['action'])->toBe('platform.workspace.created')
        ->and($mirror['workspace_id'])->toBe($workspace)
        ->and($mirror['security'])->toBeFalse()
        ->and($mirror['subject'])->toBe("workspace:{$workspace}");
});

it('keeps raw emails, tokens and passwords out of operator_audit and the audit log', function () {
    [, $token] = provisionWorkspace();
    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect(route('login'));

    $dump = Cluster::rows(Cluster::superuser(), "select (select coalesce(string_agg(t::text, ' '), '') from operator_audit t) || (select coalesce(string_agg(t::text, ' '), '') from audit_events t) as d")[0]['d'];

    expect($dump)->not->toContain('first.admin@example.test')->not->toContain('First.Admin')
        ->not->toContain($token)->not->toContain(PW);
});

it('refuses to run while the lifetime is unset and creates nothing', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => null]);

    $code = Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']);

    expect($code)->toBe(1)->and(Artisan::output())->toContain('DASHFLOW_INVITATION_LIFETIME')
        ->and(count_of('workspaces'))->toBe(0)->and(count_of('invitations'))->toBe(0)->and(count_of('operator_audit'))->toBe(0);
});

it('refuses an invalid email and creates nothing', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'not-an-email']))->not->toBe(0)
        ->and(count_of('workspaces'))->toBe(0);
});

it('leaves nothing behind when the email cannot be sent', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']))->toBe(1)
        ->and(count_of('workspaces'))->toBe(0)->and(count_of('invitations'))->toBe(0)->and(count_of('operator_audit'))->toBe(0);
});

it('shows the accept form for a valid link', function () {
    [, $token] = provisionWorkspace();

    $this->get("/invitations/{$token}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/AcceptInvitation')->where('token', $token)->has('passwordRules'));
});

it('accepts for a new user: user row, admin membership with every permission, audit, invitation used', function () {
    [$workspace, $token] = provisionWorkspace();

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect(route('login'));

    $user = User::query()->where('email', 'first.admin@example.test')->firstOrFail();
    expect($user->name)->toBe('First Admin')->and(Hash::check(PW, $user->password))->toBeTrue();

    $membership = Cluster::rows(Cluster::superuser(), 'select * from workspace_memberships')[0];
    expect($membership['workspace_id'])->toBe($workspace)->and($membership['role'])->toBe('admin')
        ->and((int) $membership['user_id'])->toBe($user->id)->and($membership['status'])->toBe('active');

    $held = array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ?', [$membership['id']]), 'permission');
    expect($held)->toEqualCanonicalizing(Permission::values())->toContain('users.manage');

    $audit = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'identity.invitation.accepted'");
    expect($audit)->toHaveCount(1)->and($audit[0]['workspace_id'])->toBe($workspace)->and($audit[0]['security'])->toBeFalse();

    expect(Cluster::rows(Cluster::superuser(), 'select used_at from invitations')[0]['used_at'])->not->toBeNull();
});

it('adds only the membership for a known email and leaves password and name untouched', function () {
    $existing = User::factory()->create(['email' => 'first.admin@example.test', 'name' => 'Existing Name', 'password' => 'existing-password-123']);
    $before = $existing->fresh()->password;
    [$workspace, $token] = provisionWorkspace();

    $this->post("/invitations/{$token}", acceptPayload(['name' => 'Someone Else']))->assertRedirect(route('login'));

    $existing->refresh();
    expect($existing->name)->toBe('Existing Name')->and($existing->password)->toBe($before)
        ->and(count_of('users'))->toBe(1);

    $membership = Cluster::rows(Cluster::superuser(), 'select * from workspace_memberships')[0];
    expect($membership['workspace_id'])->toBe($workspace)->and((int) $membership['user_id'])->toBe($existing->id)->and($membership['role'])->toBe('admin');
    expect(count_of('membership_permissions'))->toBe(count(Permission::cases()));
});

function assertNeutral($response): void
{
    $response->assertStatus(410)->assertInertia(fn ($page) => $page->component('auth/InvitationExpired'));
}

function rejections(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'identity.invitation.rejected'");
}

it('refuses a second use with the neutral page, a security event and no second membership', function () {
    [, $token] = provisionWorkspace();
    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    assertNeutral($this->post("/invitations/{$token}", acceptPayload(['email' => 'first.admin@example.test'])));
    assertNeutral($this->get("/invitations/{$token}"));

    expect(count_of('workspace_memberships'))->toBe(1)->and(count_of('membership_permissions'))->toBe(9);

    $rows = rejections();
    expect($rows)->toHaveCount(2)->and($rows[0]['security'])->toBeTrue()
        ->and(json_decode($rows[0]['after_state'], true)['reason'])->toBe('used')
        ->and($rows[0]['after_state'])->not->toContain($token);
});

it('refuses an expired link with the neutral page and a security event', function () {
    [, $token] = provisionWorkspace();
    Cluster::superuser()->exec("update invitations set expires_at = now() - interval '1 minute'");

    assertNeutral($this->post("/invitations/{$token}", acceptPayload()));

    expect(count_of('workspace_memberships'))->toBe(0)->and(count_of('users'))->toBe(0)
        ->and(json_decode(rejections()[0]['after_state'], true)['reason'])->toBe('expired');
});

it('treats a tampered or unknown token like any other refusal, without recording the token', function () {
    [, $token] = provisionWorkspace();
    $tampered = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');

    Log::spy();
    assertNeutral($this->post("/invitations/{$tampered}", acceptPayload()));
    assertNeutral($this->get('/invitations/not-a-token'));
    assertNeutral($this->get('/invitations/'.str_repeat('A', 43)));

    expect(count_of('workspace_memberships'))->toBe(0)->and(rejections())->toBe([]);
    Log::shouldHaveReceived('warning')->with('identity.invitation.rejected', ['reason' => 'unknown'])->times(3);
});

it('refuses the right token with a different email as expired, and the invitation stays usable', function () {
    [, $token] = provisionWorkspace();

    assertNeutral($this->post("/invitations/{$token}", acceptPayload(['email' => 'someone.else@example.test'])));

    expect(count_of('workspace_memberships'))->toBe(0)->and(count_of('users'))->toBe(0)
        ->and(json_decode(rejections()[0]['after_state'], true)['reason'])->toBe('email_mismatch');

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect(route('login'));
    expect(count_of('workspace_memberships'))->toBe(1);
});

it('sends an Inertia visit to the neutral page as a full visit', function () {
    [, $token] = provisionWorkspace();

    $this->post("/invitations/{$token}", acceptPayload(['email' => 'x@example.test']), ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', route('invitations.expired'));

    assertNeutral($this->get('/invitations/expired'));
});

it('creates nothing for a weak password and keeps the invitation open', function () {
    [, $token] = provisionWorkspace();

    $this->post("/invitations/{$token}", acceptPayload(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertSessionHasErrors('password');

    expect(count_of('users'))->toBe(0)->and(count_of('workspace_memberships'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select used_at from invitations')[0]['used_at'])->toBeNull();
});

it('lets only one of two uses of the same link win', function () {
    [$workspace, $token] = provisionWorkspace();
    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    // A second user with the same invitation row can no longer be created: the row is used.
    assertNeutral($this->post("/invitations/{$token}", acceptPayload(['name' => 'Other'])));
    expect(count_of('users'))->toBe(1)->and(count_of('workspace_memberships'))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select workspace_id from workspace_memberships')[0]['workspace_id'])->toBe($workspace);
});

it('stores only the token hash and offers no way to read a token back', function () {
    provisionWorkspace();

    $columns = array_column(Cluster::rows(Cluster::superuser(), "select column_name from information_schema.columns where table_name = 'invitations'"), 'column_name');

    expect($columns)->toContain('token_hash')->not->toContain('token');
});

function acceptedAudit(): array
{
    return json_decode(Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'identity.invitation.accepted'")[0]['after_state'], true);
}

function seedMember(string $workspace, int $user, string $role, string $status): string
{
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare('insert into workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) values (?, ?, ?, ?, ?, now(), now())')
        ->execute([$id, $workspace, $user, $role, $status]);

    return $id;
}

it('records membership=created for a new membership', function () {
    [, $token] = provisionWorkspace();
    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    expect(acceptedAudit()['membership'])->toBe('created');
});

it('promotes an active user member to admin with all permissions and records membership=promoted', function () {
    $existing = User::factory()->create(['email' => 'first.admin@example.test']);
    [$workspace, $token] = provisionWorkspace();
    $membership = seedMember($workspace, $existing->id, 'user', 'active');

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    $row = Cluster::rows(Cluster::superuser(), 'select * from workspace_memberships')[0];
    expect($row['id'])->toBe($membership)->and($row['role'])->toBe('admin')->and($row['status'])->toBe('active')
        ->and(count_of('membership_permissions'))->toBe(9)
        ->and(acceptedAudit()['membership'])->toBe('promoted');
});

it('leaves an active admin as is, ensures every permission and records membership=unchanged', function () {
    $existing = User::factory()->create(['email' => 'first.admin@example.test']);
    [$workspace, $token] = provisionWorkspace();
    seedMember($workspace, $existing->id, 'admin', 'active');

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    expect(count_of('membership_permissions'))->toBe(9)->and(count_of('workspace_memberships'))->toBe(1)
        ->and(acceptedAudit()['membership'])->toBe('unchanged');
});

it('refuses a suspended or removed membership as a neutral rejection and changes nothing', function (string $status) {
    $existing = User::factory()->create(['email' => 'first.admin@example.test']);
    [$workspace, $token] = provisionWorkspace();
    seedMember($workspace, $existing->id, 'user', $status);

    assertNeutral($this->post("/invitations/{$token}", acceptPayload()));

    $row = Cluster::rows(Cluster::superuser(), 'select role, status from workspace_memberships')[0];
    expect($row)->toBe(['role' => 'user', 'status' => $status])
        ->and(count_of('membership_permissions'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select used_at from invitations')[0]['used_at'])->toBeNull()
        ->and(json_decode(rejections()[0]['after_state'], true)['reason'])->toBe('membership_inactive')
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'identity.invitation.accepted'")[0]['n'])->toBe(0);
})->with(['suspended', 'removed']);

it('accepts an invitation for the role user as a user membership with no permissions', function () {
    [$workspace, $token] = provisionWorkspace();
    Cluster::superuser()->exec("update invitations set role = 'user', permissions = '[]'");

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect();

    $membership = Cluster::rows(Cluster::superuser(), 'select role, status from workspace_memberships')[0];
    expect($membership)->toBe(['role' => 'user', 'status' => 'active'])
        ->and(count_of('membership_permissions'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select used_at from invitations')[0]['used_at'])->not->toBeNull();
});

it('matches a known email case-insensitively', function () {
    $existing = User::factory()->create(['email' => 'First.ADMIN@Example.test', 'name' => 'Keep']);
    [, $token] = provisionWorkspace('first.admin@example.test');

    $this->post("/invitations/{$token}", acceptPayload(['email' => 'FIRST.admin@EXAMPLE.test']))->assertRedirect();

    expect(count_of('users'))->toBe(1)->and($existing->fresh()->name)->toBe('Keep')
        ->and((int) Cluster::rows(Cluster::superuser(), 'select user_id from workspace_memberships')[0]['user_id'])->toBe($existing->id);
});

it('refuses a link that is used between the pre-check and the locked re-check', function () {
    [, $token] = provisionWorkspace();

    // The seam: the moment the accept transaction begins (the pre-check has passed), another request wins.
    $armed = true;
    Event::listen(TransactionBeginning::class, function () use (&$armed) {
        if ($armed) {
            $armed = false;
            Cluster::superuser()->exec('update invitations set used_at = now()');
        }
    });

    assertNeutral($this->post("/invitations/{$token}", acceptPayload()));

    expect(count_of('users'))->toBe(0)->and(count_of('workspace_memberships'))->toBe(0)
        ->and(json_decode(rejections()[0]['after_state'], true)['reason'])->toBe('used');
});

it('serialises two real concurrent uses: the second blocks on the row lock and is refused', function () {
    [, $token] = provisionWorkspace();

    // A second connection as `app` holds the invitation row lock and marks it used, uncommitted.
    $other = Cluster::directApp();
    $other->beginTransaction();
    $other->exec("update invitations set used_at = now(), updated_at = now() where token_hash = '".hash('sha256', $token)."'");

    // The accept flow's pre-check still sees an unused row; commit the winner as soon as the loser asks for the lock.
    Event::listen(TransactionBeginning::class, function () use ($other) {
        if ($other->inTransaction()) {
            $other->commit();
        }
    });

    assertNeutral($this->post("/invitations/{$token}", acceptPayload()));

    expect(count_of('workspace_memberships'))->toBe(0);
});

it('does not 500 when a second invitation for the same new email creates the user first', function () {
    [$workspace, $token] = provisionWorkspace();

    // The seam: right before our user insert, a concurrent acceptance commits a user with the same email.
    $armed = true;
    User::creating(function () use (&$armed) {
        if ($armed) {
            $armed = false;
            Cluster::superuser()->exec("insert into users (name, email, password, created_at, updated_at) values ('Racer', 'first.admin@example.test', 'x', now(), now())");
        }
    });

    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect(route('login'));

    expect(count_of('users'))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select name from users')[0]['name'])->toBe('Racer')
        ->and(Cluster::rows(Cluster::superuser(), 'select workspace_id from workspace_memberships')[0]['workspace_id'])->toBe($workspace);
});

it('lets two invitations for one email give one user two memberships', function () {
    [$a, $tokenA] = provisionWorkspace('first.admin@example.test', 'A', 'a');
    [$b, $tokenB] = provisionWorkspace('first.admin@example.test', 'B', 'b');

    $this->post("/invitations/{$tokenA}", acceptPayload())->assertRedirect();
    $this->post("/invitations/{$tokenB}", acceptPayload(['name' => 'Ignored']))->assertRedirect();

    expect(count_of('users'))->toBe(1)->and(count_of('workspace_memberships'))->toBe(2)
        ->and(Cluster::rows(Cluster::superuser(), 'select name from users')[0]['name'])->toBe('First Admin');
});

it('creates nothing when the password confirmation does not match', function () {
    [, $token] = provisionWorkspace();

    $this->post("/invitations/{$token}", acceptPayload(['password_confirmation' => 'something-else-entirely']))
        ->assertSessionHasErrors('password');

    expect(count_of('users'))->toBe(0)->and(count_of('workspace_memberships'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select used_at from invitations')[0]['used_at'])->toBeNull();
});

it('lets a signed-in user, with another Workspace active in the session, open and accept', function () {
    $other = Cluster::workspace('Other');
    $user = User::factory()->create(['email' => 'first.admin@example.test']);
    [$workspace, $token] = provisionWorkspace();
    // The person is an active member of the other Workspace (a session naming one without a membership is ended).
    Cluster::superuser()->prepare("INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, 'user', 'active', now(), now())")
        ->execute([(string) Str::uuid7(), $other, $user->id]);

    $this->actingAs($user)->withSession(['workspace_id' => $other]);

    $this->get("/invitations/{$token}")->assertOk()->assertInertia(fn ($page) => $page->component('auth/AcceptInvitation'));
    $this->post("/invitations/{$token}", acceptPayload())->assertRedirect(route('login'));

    expect(Cluster::rows(Cluster::superuser(), 'select workspace_id from workspace_memberships where workspace_id = ?', [$workspace]))->toHaveCount(1);
});

it('sends Referrer-Policy and Cache-Control no-store on every invitation response', function () {
    [, $token] = provisionWorkspace();

    foreach ([
        $this->get("/invitations/{$token}"),
        $this->post("/invitations/{$token}", acceptPayload(['password' => 'x'])),
        $this->post("/invitations/{$token}", acceptPayload(['email' => 'nobody@example.test'])),
        $this->get('/invitations/expired'),
    ] as $response) {
        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('records the OS user as the operator actor in operator_audit and the mirrored Workspace event', function () {
    provisionWorkspace();

    $operator = Cluster::rows(Cluster::superuser(), 'select actor from operator_audit')[0]['actor'];
    $mirror = Cluster::rows(Cluster::superuser(), 'select actor from audit_events')[0]['actor'];
    $created = Cluster::rows(Cluster::superuser(), 'select created_by from invitations')[0]['created_by'];

    expect($operator)->toMatch('/\Aoperator:[a-z0-9_.-]+\z/')->and($mirror)->toBe($operator)->and($created)->toBe($operator);
});

it('accepts a descriptive label with spaces and capitals, and the same label twice', function () {
    provisionWorkspace('a@example.test', 'Acme', 'Acme Industries - Production');
    $second = Artisan::call('dashflow:workspace:create', ['name' => 'Other', 'label' => 'Acme Industries - Production', 'admin-email' => 'b@example.test']);

    expect($second)->toBe(0)->and(count_of('workspaces'))->toBe(2)
        ->and(Cluster::rows(Cluster::superuser(), 'select label from workspaces order by name')[0]['label'])->toBe('Acme Industries - Production');
});

it('refuses control characters in the name or the label, and a label over 64 characters', function (string $name, string $label) {
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);

    expect(Artisan::call('dashflow:workspace:create', ['name' => $name, 'label' => $label, 'admin-email' => 'a@example.test']))->not->toBe(0)
        ->and(count_of('workspaces'))->toBe(0);
})->with([
    ["Line\nBreak", 'ok'],
    ["Tab\there", 'ok'],
    ['Acme', "new\nline"],
    ['Acme', "tab\there"],
    ['Acme', "bell\x07"],
    ['Acme', "line\u{2028}separator"],
    ['Acme', "paragraph\u{2029}separator"],
    ['Acme', str_repeat('a', 65)],
]);

it('caps the lifetime at 8760 hours and names the bound', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => '8761']);

    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']))->toBe(1)
        ->and(Artisan::output())->toContain('8760')->and(count_of('workspaces'))->toBe(0);

    config(['dashflow.tunables.users.invitation_lifetime.value' => '8760']);
    expect(Artisan::call('dashflow:workspace:create', ['name' => 'Acme', 'label' => 'acme', 'admin-email' => 'a@example.test']))->toBe(0);
});

it('escapes the Workspace name in the invitation email', function () {
    provisionWorkspace('first.admin@example.test', '<script>x</script>', 'acme');

    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    assert($message instanceof Email);

    expect((string) $message->getTextBody())->not->toContain('<script>')->toContain('&lt;script&gt;');
});

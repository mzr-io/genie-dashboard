<?php

use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Identity\Contracts\ErrorCode as IdentityErrorCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Database\Support\Cluster;

// Story 1.21 against the real PostgreSQL: an Admin with `users.manage` invites, re-sends and revokes through the
// SECURITY DEFINER functions, the audit and outbox rows land in the same transaction, the email goes out after the
// commit, and acceptance honours the invited role and permissions.
const INVITER_PASSWORD = 'inviter-password-1';
const INVITEE_PASSWORD = 'a-long-enough-password';

beforeEach(function () {
    $this->withoutVite();
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);
});

/** An active member of the Workspace; returns [user ID, membership ID]. */
function inviteMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], string $status = 'active'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(INVITER_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

/** Signs an Admin of the Workspace in to the Admin area; returns [user ID, membership ID]. */
function inviteAdmin(string $workspaceId, array $permissions = ['users.manage', 'blocks.edit', 'audit.view'], string $email = 'ada@example.test'): array
{
    [$user, $membership] = inviteMember($workspaceId, $email, 'admin', $permissions);
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function invitePost(array $payload)
{
    return test()->postJson('/api/v1/admin/invitations', $payload, ['Referer' => 'http://localhost:8000']);
}

function invitationRows(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from invitations order by created_at');
}

function inviteCount(string $table, string $where = 'true'): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table} where {$where}")[0]['n'];
}

function inviteMails(): int
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
}

/** The plain token in the last email sent. */
function inviteToken(): string
{
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    assert($message instanceof Email);

    preg_match('#/invitations/([A-Za-z0-9_-]{43})#', (string) $message->getTextBody(), $match);
    expect($match)->toHaveCount(2);

    return $match[1];
}

function inviteAudits(string $action = 'access.membership.invited'): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at', [$action]);
}

function inviteEvents(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'access.membership.changed' order by occurred_at, subject_seq");
}

function acceptInvite(string $token, string $email, string $name = 'New Person')
{
    return test()->post("/invitations/{$token}", ['email' => $email, 'name' => $name, 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD]);
}

it('invites a user: hashed invitation bound to the email, mailed after commit, audited, with an outbox event and the list showing Invited', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = inviteAdmin($workspace);

    $response = invitePost(['email' => ' Bo@Example.test ', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    $row = invitationRows()[0];

    expect($row)->toMatchArray(['workspace_id' => $workspace, 'email' => 'bo@example.test', 'role' => 'user', 'created_by' => $membership, 'used_at' => null, 'revoked_at' => null])
        ->and($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(json_decode($row['permissions'], true))->toBe([])
        ->and(strlen($token))->toBe(43)
        ->and(strtotime($row['expires_at']))->toBeGreaterThan(time() + 47 * 3600)->toBeLessThan(time() + 49 * 3600)
        ->and(inviteMails())->toBe(1)
        ->and($response->json('data'))->toMatchArray(['kind' => 'invitation', 'invitation_id' => $row['id'], 'email' => 'bo@example.test', 'role' => 'user', 'status' => 'invited', 'name' => '', 'groups' => []])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // Never a token or a hash in the response.
    expect($response->getContent())->not->toContain($token)->not->toContain($row['token_hash'])->not->toContain('token_hash');

    $audit = inviteAudits();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($membership)
        ->and($audit[0]['subject'])->toBe('invitation:'.$row['id'])
        ->and($audit[0]['security'])->toBeFalse()
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['invitation_id' => $row['id'], 'role' => 'user', 'status' => 'invited', 'permission_count' => 0]);

    $event = inviteEvents();
    expect($event)->toHaveCount(1)
        ->and($event[0]['subject'])->toBe('invitation:'.$row['id'])
        ->and(json_decode($event[0]['data'], true))->toEqual(['invitation_id' => $row['id'], 'role' => 'user', 'status' => 'invited', 'change' => 'invited']);

    // The list shows it as Invited.
    $listed = collect($this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->json('data'))->firstWhere('email', 'bo@example.test');
    expect($listed)->toMatchArray(['kind' => 'invitation', 'status' => 'invited', 'role' => 'user']);
});

it('invites an Admin with permissions the inviter holds, after the password is confirmed', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => INVITER_PASSWORD])->assertCreated()
        ->assertJsonPath('data.role', 'admin');

    expect(json_decode(invitationRows()[0]['permissions'], true))->toEqualCanonicalizing(['blocks.edit', 'audit.view'])
        ->and(json_decode(inviteAudits()[0]['after_state'], true)['permission_count'])->toBe(2)
        ->and(inviteMails())->toBe(1);
});

it('asks for the password on every Admin invitation, also with no permissions', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => []])->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);
    expect(invitationRows())->toBe([]);

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => [], 'confirm_password' => INVITER_PASSWORD])->assertCreated();

    expect(json_decode(invitationRows()[0]['permissions'], true))->toBe([]);
});

it('does not reset the password throttle with a right password', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    $wrong = fn () => invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => [], 'confirm_password' => 'wrong']);

    foreach (range(1, 5) as $ignored) {
        $wrong()->assertStatus(422);
    }

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => [], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $wrong()->assertStatus(422);
    $wrong()->assertStatus(429);
});

it('blocks an Admin invitation with a wrong or missing password with a field error and creates nothing', function (array $extra) {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    $response = invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit']] + $extra)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('confirm_password')
        ->and(invitationRows())->toBe([])
        ->and(inviteMails())->toBe(0)
        ->and(inviteAudits())->toBe([])
        ->and(inviteEvents())->toBe([]);
})->with([
    'wrong' => [['confirm_password' => 'not-the-password']],
    'missing' => [[]],
]);

it('throttles password confirmations like the password change', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    $attempt = fn () => invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => 'wrong']);

    foreach (range(1, 6) as $ignored) {
        $attempt()->assertStatus(422);
    }

    $attempt()->assertStatus(429);
    // Even the right password waits.
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => INVITER_PASSWORD])->assertStatus(429);
    expect(invitationRows())->toBe([]);
});

it('refuses a permission the inviter does not hold with 403 access.permission_not_held, before looking at the password, and creates nothing', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace, ['users.manage', 'blocks.edit']);

    $response = invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit', 'blocks.publish']])->assertForbidden();

    expect($response->json('error.code'))->toBe(ErrorCode::PermissionNotHeld->value)
        ->and(invitationRows())->toBe([])
        ->and(inviteMails())->toBe(0)
        ->and(inviteAudits())->toBe([]);

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['access.manage'], 'confirm_password' => INVITER_PASSWORD])->assertForbidden();
    expect(invitationRows())->toBe([]);
});

it('reads the inviter\'s permissions on every request', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    $payload = ['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['audit.view'], 'confirm_password' => INVITER_PASSWORD];

    invitePost($payload)->assertCreated();
    Cluster::superuser()->exec("DELETE FROM membership_permissions WHERE permission = 'audit.view'");

    invitePost(['email' => 'cy@example.test'] + $payload)->assertForbidden();
    expect(invitationRows())->toHaveCount(1);
});

it('refuses permissions outside the closed catalogue and permissions on a user invitation', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['everything.manage'], 'confirm_password' => INVITER_PASSWORD])->assertStatus(422);
    invitePost(['email' => 'bo@example.test', 'role' => 'user', 'permissions' => ['blocks.edit']])->assertStatus(422)->assertJsonStructure(['errors' => ['permissions']]);
    invitePost(['email' => 'bo@example.test', 'role' => 'owner'])->assertStatus(422);

    expect(invitationRows())->toBe([]);
});

it('refuses an invalid email, a member of the Workspace and a pending duplicate with a field error', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    inviteMember($workspace, 'member@example.test');
    inviteMember($workspace, 'gone@example.test', 'user', [], 'suspended');
    $mailsBefore = inviteMails();

    foreach (['not-an-email', "bo@example.test\nbcc:evil@example.test", str_repeat('a', 250).'@example.test', ''] as $email) {
        invitePost(['email' => $email, 'role' => 'user'])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    invitePost(['email' => 'MEMBER@example.test', 'role' => 'user'])->assertStatus(422)->assertJsonPath('reason', 'member')->assertJsonStructure(['errors' => ['email']]);
    invitePost(['email' => 'gone@example.test', 'role' => 'user'])->assertStatus(422)->assertJsonPath('reason', 'member');

    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $pending = invitationRows()[0]['id'];

    invitePost(['email' => 'BO@example.test', 'role' => 'user'])->assertStatus(422)
        ->assertJsonPath('reason', 'pending')->assertJsonPath('invitation_id', $pending)->assertJsonStructure(['errors' => ['email']]);

    expect(invitationRows())->toHaveCount(1)->and(inviteMails())->toBe($mailsBefore + 1);
});

it('invites again once the earlier invitation was used, revoked or has expired', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    Cluster::superuser()->exec("UPDATE invitations SET expires_at = now() - interval '1 hour'");
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();

    expect(invitationRows())->toHaveCount(2);

    $pending = invitationRows()[1]['id'];
    $this->deleteJson("/api/v1/admin/invitations/{$pending}", [], ['Referer' => 'http://localhost:8000'])->assertNoContent();
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();

    expect(invitationRows())->toHaveCount(3);
});

it('answers 422 access.invitations_not_configured while the lifetime is unset and creates nothing', function () {
    config(['dashflow.tunables.users.invitation_lifetime.value' => null]);
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);

    $response = invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertStatus(422);

    expect($response->json('error.code'))->toBe(ErrorCode::InvitationsNotConfigured->value)
        ->and(invitationRows())->toBe([])
        ->and(inviteMails())->toBe(0)
        ->and(inviteAudits())->toBe([]);
});

it('re-sends: a new token replaces the old one, the old link stops working, and the row keeps one active token', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $old = inviteToken();
    $id = invitationRows()[0]['id'];

    $response = $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertOk();
    $new = inviteToken();

    expect($new)->not->toBe($old)
        ->and(invitationRows())->toHaveCount(1)
        ->and(invitationRows()[0]['token_hash'])->toBe(hash('sha256', $new))
        ->and(inviteMails())->toBe(2)
        ->and($response->getContent())->not->toContain($new)->not->toContain($old)
        ->and(inviteAudits())->toHaveCount(2)
        ->and(json_decode(inviteAudits()[1]['after_state'], true)['reason'])->toBe('resent')
        ->and(inviteAudits()[1]['actor'])->toBe($membership)
        ->and(array_map(fn ($event) => json_decode($event['data'], true)['change'], inviteEvents()))->toBe(['invited', 'resent']);

    $this->get("/invitations/{$old}")->assertStatus(410);
    $this->get("/invitations/{$new}")->assertOk();
});

it('re-sends only invitations of the active Workspace and refuses used, revoked, expired and unknown ones with 404', function () {
    $mine = Cluster::workspace('Mine');
    $other = Cluster::workspace('Other');
    inviteAdmin($mine);
    inviteAdmin($other, ['users.manage'], 'eve@example.test');

    // Eve (Other) invites; Ada's session is the active one below.
    $foreign = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, created_by, created_at, updated_at) VALUES (?, ?, 'x@example.test', ?, 'user', now() + interval '2 days', 'test', now(), now())")
        ->execute([$foreign, $other, hash('sha256', 'foreign')]);
    $headers = ['Referer' => 'http://localhost:8000'];
    [$user] = inviteMember($mine, 'zed@example.test', 'admin', ['users.manage']);
    $this->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $mine, 'area' => 'admin']);

    $this->postJson("/api/v1/admin/invitations/{$foreign}/resend", [], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/admin/invitations/{$foreign}", [], $headers)->assertNotFound();
    $this->postJson('/api/v1/admin/invitations/not-a-uuid/resend', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v1/admin/invitations/'.Str::uuid7(), [], $headers)->assertNotFound();

    $untouched = Cluster::rows(Cluster::superuser(), 'select token_hash, revoked_at from invitations where id = ?', [$foreign])[0];
    expect($untouched)->toBe(['token_hash' => hash('sha256', 'foreign'), 'revoked_at' => null])
        ->and(inviteMails())->toBe(0);

    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $id = Cluster::rows(Cluster::superuser(), "select id from invitations where email = 'bo@example.test'")[0]['id'];

    foreach (['used_at = now()', 'revoked_at = now()', "expires_at = now() - interval '1 minute'"] as $state) {
        Cluster::superuser()->exec("UPDATE invitations SET {$state} WHERE id = '{$id}'");
        $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/admin/invitations/{$id}", [], $headers)->assertNotFound();
        Cluster::superuser()->exec("UPDATE invitations SET used_at = null, revoked_at = null, expires_at = now() + interval '2 days' WHERE id = '{$id}'");
    }
});

it('refuses a re-send by an Admin who does not hold what the invitation grants, and keeps the old token', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace, ['users.manage', 'blocks.edit']);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $old = inviteToken();
    $id = invitationRows()[0]['id'];

    [$user] = inviteMember($workspace, 'lim@example.test', 'admin', ['users.manage']);
    $this->flushSession();
    auth()->forgetGuards();
    $this->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertForbidden()
        ->assertJsonPath('error.code', ErrorCode::PermissionNotHeld->value);

    expect(invitationRows()[0]['token_hash'])->toBe(hash('sha256', $old))->and(inviteMails())->toBe(1);
});

it('revokes: revoked_at is set, the link shows the expired response, and it is audited with an outbox event', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    $id = invitationRows()[0]['id'];

    $this->deleteJson("/api/v1/admin/invitations/{$id}", [], ['Referer' => 'http://localhost:8000'])->assertNoContent();

    expect(invitationRows()[0]['revoked_at'])->not->toBeNull();

    $this->get("/invitations/{$token}")->assertStatus(410)->assertInertia(fn ($page) => $page->component('auth/InvitationExpired'));
    acceptInvite($token, 'bo@example.test')->assertStatus(410);
    expect(inviteCount('users', "email = 'bo@example.test'"))->toBe(0)->and(inviteCount('workspace_memberships'))->toBe(1);

    $audit = inviteAudits('access.membership.changed');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($membership)
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['invitation_id' => $id, 'status' => 'invited'])
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['invitation_id' => $id, 'status' => 'revoked'])
        ->and(json_decode(array_last(inviteEvents())['data'], true))->toMatchArray(['invitation_id' => $id, 'status' => 'revoked', 'change' => 'revoked']);

    // A revoked invitation leaves the list, and cannot be revoked again or re-sent.
    expect(array_column($this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->json('data'), 'email'))->not->toContain('bo@example.test');
    $this->deleteJson("/api/v1/admin/invitations/{$id}", [], ['Referer' => 'http://localhost:8000'])->assertNotFound();
    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertNotFound();
});

it('keeps the invitation Invited and answers 503 with its ID when the email cannot be sent, then Resend delivers it', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    Log::spy();
    $mailer = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down for bo@example.test'));

    $response = invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertStatus(503);
    $id = invitationRows()[0]['id'];

    expect($response->json('error.code'))->toBe(IdentityErrorCode::InvitationDeliveryFailed->value)
        ->and($response->json('invitation_id'))->toBe($id)
        ->and(inviteAudits())->toHaveCount(1)
        ->and(inviteEvents())->toHaveCount(1);

    // The address never reaches the log; the invitation ID does.
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'identity.invitation.delivery_failed'
        && ($context['invitation_id'] ?? null) === $id
        && ! str_contains(json_encode($context), 'bo@example.test'));

    $listed = collect($this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->json('data'))->firstWhere('email', 'bo@example.test');
    expect($listed['status'])->toBe('invited');

    Mail::swap($mailer);
    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertOk();
    expect(invitationRows()[0]['token_hash'])->toBe(hash('sha256', inviteToken()));
});

it('sends no email when the request fails and rolls back', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    inviteMember($workspace, 'member@example.test');

    invitePost(['email' => 'member@example.test', 'role' => 'user'])->assertStatus(422);

    expect(inviteMails())->toBe(0)->and(invitationRows())->toBe([])->and(inviteAudits())->toBe([]);
});

it('accepts a user invitation as a user membership with no permissions, Active', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();

    acceptInvite($token, 'bo@example.test')->assertRedirect(route('login'));

    $membership = Cluster::rows(Cluster::superuser(), "select m.role, m.status, m.id from workspace_memberships m join users u on u.id = m.user_id where u.email = 'bo@example.test'")[0];
    expect($membership['role'])->toBe('user')->and($membership['status'])->toBe('active')
        ->and(inviteCount('membership_permissions', "membership_id = '{$membership['id']}'"))->toBe(0)
        ->and(invitationRows()[0]['used_at'])->not->toBeNull();

    // Single use.
    $this->get("/invitations/{$token}")->assertStatus(410);
});

it('accepts an Admin invitation with exactly the invited permissions', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();

    acceptInvite(inviteToken(), 'bo@example.test')->assertRedirect();

    $membership = Cluster::rows(Cluster::superuser(), "select m.id, m.role from workspace_memberships m join users u on u.id = m.user_id where u.email = 'bo@example.test'")[0];
    $held = array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ?', [$membership['id']]), 'permission');

    expect($membership['role'])->toBe('admin')->and($held)->toEqualCanonicalizing(['blocks.edit', 'audit.view']);
});

it('caps an Admin invitation at the permissions the inviter holds at acceptance time', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $token = inviteToken();

    Cluster::superuser()->exec("DELETE FROM membership_permissions WHERE permission = 'audit.view'");

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    $held = array_column(Cluster::rows(Cluster::superuser(), "select p.permission from membership_permissions p join workspace_memberships m on m.id = p.membership_id join users u on u.id = m.user_id where u.email = 'bo@example.test'"), 'permission');
    expect($held)->toBe(['blocks.edit']);
});

it('refuses acceptance, with the neutral response and a security event, when the inviter was demoted, deactivated or removed', function (string $change) {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $token = inviteToken();

    Cluster::superuser()->exec(match ($change) {
        'demoted' => "UPDATE workspace_memberships SET role = 'user'",
        'deactivated' => "UPDATE workspace_memberships SET status = 'suspended'",
        'removed' => "UPDATE workspace_memberships SET status = 'removed'",
    });

    $this->get("/invitations/{$token}")->assertOk();
    acceptInvite($token, 'bo@example.test')->assertStatus(410);

    expect(inviteCount('users', "email = 'bo@example.test'"))->toBe(0)
        ->and(inviteCount('workspace_memberships'))->toBe(1)
        ->and(invitationRows()[0]['used_at'])->toBeNull()
        ->and(json_decode(Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'identity.invitation.rejected'")[0]['after_state'], true)['reason'])->toBe('inviter_gone');
})->with(['demoted', 'deactivated', 'removed']);

it('refuses a user invitation too when its inviter is gone', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    Cluster::superuser()->exec("UPDATE workspace_memberships SET status = 'removed'");

    acceptInvite(inviteToken(), 'bo@example.test')->assertStatus(410);

    expect(inviteCount('workspace_memberships', "role = 'user'"))->toBe(0);
});

it('refuses an invitation whose created_by is neither a membership ID nor an operator value', function (?string $createdBy) {
    $workspace = Cluster::workspace('Acme');
    $token = str_repeat('c', 43);
    Cluster::superuser()->prepare("INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, created_by, created_at, updated_at) VALUES (?, ?, 'bo@example.test', ?, 'user', now() + interval '2 days', ?, now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, hash('sha256', $token), $createdBy]);

    acceptInvite($token, 'bo@example.test')->assertStatus(410);

    expect(inviteCount('workspace_memberships'))->toBe(0);
})->with([null, 'test', 'operator', 'someone', '018f0000-0000-7000-8000-000000000000']);

it('refuses an invitation with a role other than user or admin', function () {
    $workspace = Cluster::workspace('Acme');
    $token = str_repeat('d', 43);
    Cluster::superuser()->exec('ALTER TABLE invitations DROP CONSTRAINT invitations_role_check');
    Cluster::superuser()->prepare("INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, created_by, created_at, updated_at) VALUES (?, ?, 'bo@example.test', ?, 'owner', now() + interval '2 days', 'operator:root', now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, hash('sha256', $token)]);

    acceptInvite($token, 'bo@example.test')->assertStatus(410);

    expect(inviteCount('workspace_memberships'))->toBe(0);
    Cluster::superuser()->exec("DELETE FROM invitations; ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('user', 'admin'))");
});

function acceptedMembership(): string
{
    return json_decode(Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'identity.invitation.accepted'")[0]['after_state'], true)['membership'];
}

it('leaves an existing active Admin an Admin when a user invitation is accepted (membership unchanged)', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    [, $existing] = inviteMember($workspace, 'bo@example.test', 'admin', ['audit.view']);

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    expect(Cluster::rows(Cluster::superuser(), 'select role from workspace_memberships where id = ?', [$existing])[0]['role'])->toBe('admin')
        ->and(acceptedMembership())->toBe('unchanged')
        ->and(inviteCount('membership_permissions', "membership_id = '{$existing}'"))->toBe(1);
});

it('leaves an existing active User a User when a user invitation is accepted (membership unchanged)', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    [, $existing] = inviteMember($workspace, 'bo@example.test');

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    expect(Cluster::rows(Cluster::superuser(), 'select role from workspace_memberships where id = ?', [$existing])[0]['role'])->toBe('user')
        ->and(acceptedMembership())->toBe('unchanged')
        ->and(inviteCount('membership_permissions', "membership_id = '{$existing}'"))->toBe(0);
});

it('promotes an existing active User by an Admin invitation with exactly the invited permission (membership promoted)', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace, ['users.manage', 'blocks.edit']);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $token = inviteToken();
    [, $existing] = inviteMember($workspace, 'bo@example.test');

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    $held = array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ?', [$existing]), 'permission');
    expect(Cluster::rows(Cluster::superuser(), 'select role from workspace_memberships where id = ?', [$existing])[0]['role'])->toBe('admin')
        ->and($held)->toBe(['blocks.edit'])
        ->and(acceptedMembership())->toBe('promoted');
});

it('makes the resender the inviter of record: created_by changes, the audit names both, and acceptance is capped by the resender', function () {
    $workspace = Cluster::workspace('Acme');
    [, $creator] = inviteAdmin($workspace, ['users.manage', 'blocks.edit', 'audit.view']);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $id = invitationRows()[0]['id'];

    [$otherUser, $resender] = inviteMember($workspace, 'other@example.test', 'admin', ['users.manage', 'blocks.edit', 'audit.view']);
    $this->flushSession();
    auth()->forgetGuards();
    $this->actingAs(User::query()->findOrFail($otherUser))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertOk();
    $token = inviteToken();

    expect(invitationRows()[0]['created_by'])->toBe($resender);

    $audit = json_decode(inviteAudits()[1]['after_state'], true);
    $before = json_decode(inviteAudits()[1]['before_state'], true);
    expect($audit['inviter_id'])->toBe($resender)->and($before['inviter_id'])->toBe($creator)
        ->and(inviteAudits()[1]['actor'])->toBe($resender);

    // The creator still holds both; the resender then loses one: the resender caps the grant.
    Cluster::superuser()->prepare("DELETE FROM membership_permissions WHERE membership_id = ? AND permission = 'audit.view'")->execute([$resender]);

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    $held = array_column(Cluster::rows(Cluster::superuser(), "select p.permission from membership_permissions p join workspace_memberships m on m.id = p.membership_id join users u on u.id = m.user_id where u.email = 'bo@example.test'"), 'permission');
    expect($held)->toBe(['blocks.edit']);
});

it('refuses an address that has a membership of any status, also a deactivated or removed one', function (string $status) {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    inviteMember($workspace, 'gone@example.test', 'user', [], $status);

    invitePost(['email' => 'gone@example.test', 'role' => 'user'])->assertStatus(422)->assertJsonPath('reason', 'member')->assertJsonStructure(['errors' => ['email']]);

    expect(invitationRows())->toBe([]);
})->with(['suspended', 'removed']);

it('answers a resend refused for missing permissions with a specific message', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace, ['users.manage', 'blocks.edit']);
    invitePost(['email' => 'bo@example.test', 'role' => 'admin', 'permissions' => ['blocks.edit'], 'confirm_password' => INVITER_PASSWORD])->assertCreated();
    $id = invitationRows()[0]['id'];
    Cluster::superuser()->exec("DELETE FROM membership_permissions WHERE permission = 'blocks.edit'");

    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertForbidden()
        ->assertJsonPath('error.code', ErrorCode::PermissionNotHeld->value)
        ->assertJsonPath('error.message', 'You cannot resend this invitation: it grants permissions you do not hold.');
});

it('lists the same permissions in the enum, the database CHECKs and labels.ts', function () {
    $labels = file_get_contents(base_path('resources/js/locales/labels.ts'));
    preg_match('/permissionLabels: \{(.*?)\} as Record/s', $labels, $block);
    preg_match_all("/'([a-z_.]+)':/", $block[1] ?? '', $keys);
    $expected = Permission::values();
    sort($expected);

    $fromLabels = $keys[1];
    sort($fromLabels);
    expect($fromLabels)->toBe($expected);

    foreach (['invitations_permissions_check', 'membership_permissions_permission_check'] as $constraint) {
        $definition = Cluster::rows(Cluster::superuser(), 'select pg_get_constraintdef(oid) as d from pg_constraint where conname = ?', [$constraint])[0]['d'];
        preg_match_all('/[\'"]([a-z_.]+)[\'"]/', $definition, $found);
        $found = array_values(array_diff(array_unique($found[1]), ['array']));
        sort($found);

        expect($found)->toBe($expected, $constraint);
    }
});

it('never promotes or demotes an existing member by an invitation of another role', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    [, $existing] = inviteMember(Cluster::workspace('Elsewhere'), 'bo@example.test', 'admin', ['blocks.edit']);

    acceptInvite($token, 'bo@example.test')->assertRedirect();

    $roles = Cluster::rows(Cluster::superuser(), "select m.role from workspace_memberships m join users u on u.id = m.user_id where u.email = 'bo@example.test' and m.workspace_id = ?", [$workspace]);
    expect($roles)->toBe([['role' => 'user']])->and($existing)->not->toBeNull();
});

it('keeps the operator\'s first-Admin invitation at every permission', function () {
    $workspace = Cluster::workspace('Acme');
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, created_by, created_at, updated_at) VALUES (?, ?, 'first@example.test', ?, 'admin', now() + interval '2 days', 'operator:root', now(), now())")
        ->execute([$id, $workspace, hash('sha256', str_repeat('a', 43))]);

    expect(json_decode(invitationRows()[0]['permissions'], true))->toEqualCanonicalizing(Permission::values());

    acceptInvite(str_repeat('a', 43), 'first@example.test')->assertRedirect();

    expect(inviteCount('membership_permissions'))->toBe(9);
});

it('creates, replaces and revokes only through functions owned by migrator and bound to the Workspace setting', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $admin] = inviteMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    [, $foreignAdmin] = inviteMember($other, 'eve@example.test', 'admin', ['users.manage']);
    $owners = Cluster::rows(Cluster::superuser(), "select p.proname, r.rolname, p.prosecdef from pg_proc p join pg_roles r on r.oid = p.proowner where p.proname in ('access_create_invitation', 'access_replace_invitation', 'access_revoke_invitation') order by 1");

    expect($owners)->toBe([
        ['proname' => 'access_create_invitation', 'rolname' => 'migrator', 'prosecdef' => true],
        ['proname' => 'access_replace_invitation', 'rolname' => 'migrator', 'prosecdef' => true],
        ['proname' => 'access_revoke_invitation', 'rolname' => 'migrator', 'prosecdef' => true],
    ]);

    $app = Cluster::directApp();
    $expires = date('c', time() + 3600);
    $create = fn (string $id, string $email, string $hash, string $by) => fn ($pdo) => Cluster::rows($pdo, 'select * from access_create_invitation(?, ?, ?, ?, ?::jsonb, ?, ?)', [$id, $email, $hash, 'user', '[]', $by, $expires]);

    // `app` still has no INSERT and no UPDATE of anything but used_at on the table.
    expect(fn () => $app->exec("INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, created_at, updated_at) VALUES ('".Str::uuid7()."', '{$workspace}', 'a@example.test', '".str_repeat('b', 64)."', 'user', now(), now(), now())"))->toThrow(PDOException::class)
        ->and(fn () => $app->exec('UPDATE invitations SET revoked_at = now()'))->toThrow(PDOException::class);

    // With no Workspace setting every function refuses.
    expect(fn () => Cluster::rows($app, 'select * from access_create_invitation(?, ?, ?, ?, ?::jsonb, ?, ?)', [(string) Str::uuid7(), 'a@example.test', str_repeat('c', 64), 'user', '[]', $admin, $expires]))->toThrow(PDOException::class)
        ->and(fn () => Cluster::rows($app, 'select * from access_replace_invitation(?, ?, ?, ?)', [(string) Str::uuid7(), str_repeat('c', 64), $expires, $admin]))->toThrow(PDOException::class)
        ->and(fn () => Cluster::rows($app, 'select * from access_revoke_invitation(?)', [(string) Str::uuid7()]))->toThrow(PDOException::class);

    // The Workspace comes from the setting, never from an argument.
    $id = (string) Str::uuid7();
    $created = Cluster::inWorkspace($app, $workspace, $create($id, 'A@Example.test', str_repeat('d', 64), $admin));
    expect($created)->toBe([['invitation_id' => $id, 'was_created' => true]])
        ->and(Cluster::rows(Cluster::superuser(), 'select workspace_id, email from invitations')[0])->toBe(['workspace_id' => $workspace, 'email' => 'a@example.test']);

    $replaced = Cluster::inWorkspace($app, $other, fn ($pdo) => Cluster::rows($pdo, 'select * from access_replace_invitation(?, ?, ?, ?)', [$id, str_repeat('e', 64), $expires, $foreignAdmin]));
    $revoked = Cluster::inWorkspace($app, $other, fn ($pdo) => Cluster::rows($pdo, 'select * from access_revoke_invitation(?)', [$id]));
    expect($replaced)->toBe([])->and($revoked)->toBe([])
        ->and(invitationRows()[0]['token_hash'])->toBe(str_repeat('d', 64))->and(invitationRows()[0]['revoked_at'])->toBeNull();

    // A second create for the same email in the same Workspace returns the pending one.
    $again = Cluster::inWorkspace($app, $workspace, $create((string) Str::uuid7(), 'a@example.test', str_repeat('f', 64), $admin));
    expect($again)->toBe([['invitation_id' => $id, 'was_created' => false]])->and(invitationRows())->toHaveCount(1);

    // The replacement reports the previous inviter of record.
    [, $second] = inviteMember($workspace, 'second@example.test', 'admin', ['users.manage']);
    $swap = Cluster::inWorkspace($app, $workspace, fn ($pdo) => Cluster::rows($pdo, 'select * from access_replace_invitation(?, ?, ?, ?)', [$id, str_repeat('9', 64), $expires, $second]));
    expect($swap[0]['previous_created_by'])->toBe($admin)->and(invitationRows()[0]['created_by'])->toBe($second);
});

it('rejects a forged inviter in the create and replace functions', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $admin] = inviteMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    [, $user] = inviteMember($workspace, 'bo@example.test', 'user');
    [, $suspended] = inviteMember($workspace, 'cy@example.test', 'admin', [], 'suspended');
    [, $foreign] = inviteMember($other, 'eve@example.test', 'admin');
    $app = Cluster::directApp();
    $expires = date('c', time() + 3600);
    $id = (string) Str::uuid7();
    Cluster::inWorkspace($app, $workspace, fn ($pdo) => Cluster::rows($pdo, 'select * from access_create_invitation(?, ?, ?, ?, ?::jsonb, ?, ?)', [$id, 'a@example.test', str_repeat('d', 64), 'user', '[]', $admin, $expires]));

    foreach (['operator:root', 'x', '', $user, $suspended, $foreign, (string) Str::uuid7()] as $forged) {
        expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => Cluster::rows($pdo, 'select * from access_create_invitation(?, ?, ?, ?, ?::jsonb, ?, ?)', [(string) Str::uuid7(), 'n@example.test', str_repeat('a', 64), 'user', '[]', $forged, $expires])))->toThrow(PDOException::class)
            ->and(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => Cluster::rows($pdo, 'select * from access_replace_invitation(?, ?, ?, ?)', [$id, str_repeat('a', 64), $expires, $forged])))->toThrow(PDOException::class);
    }

    expect(invitationRows())->toHaveCount(1)->and(invitationRows()[0]['token_hash'])->toBe(str_repeat('d', 64))->and(invitationRows()[0]['created_by'])->toBe($admin);
});

it('accepts only catalogue permissions in an invitation row', function () {
    $workspace = Cluster::workspace('Acme');

    expect(fn () => Cluster::superuser()->exec("INSERT INTO invitations (id, workspace_id, email, token_hash, role, permissions, expires_at, created_at, updated_at) VALUES ('".Str::uuid7()."', '{$workspace}', 'a@example.test', '".str_repeat('b', 64)."', 'admin', '[\"root.everything\"]', now(), now(), now())"))
        ->toThrow(PDOException::class);
});

it('keeps tokens, hashes and clear emails out of the audit log and the outbox', function () {
    $workspace = Cluster::workspace('Acme');
    inviteAdmin($workspace);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertCreated();
    $token = inviteToken();
    $id = invitationRows()[0]['id'];
    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], ['Referer' => 'http://localhost:8000'])->assertOk();
    $resent = inviteToken();
    $this->deleteJson("/api/v1/admin/invitations/{$id}", [], ['Referer' => 'http://localhost:8000'])->assertNoContent();

    $dump = Cluster::rows(Cluster::superuser(), "select (select coalesce(string_agg(t::text, ' '), '') from audit_events t) || ' ' || (select coalesce(string_agg(t::text, ' '), '') from outbox_events t) as d")[0]['d'];

    expect($dump)->not->toContain('bo@example.test')->not->toContain('Bo@')
        ->not->toContain($token)->not->toContain($resent)
        ->not->toContain(hash('sha256', $token))->not->toContain(hash('sha256', $resent))
        ->not->toContain(INVITER_PASSWORD);
});

it('denies the invitation routes without users.manage, in the User area and to a person with no session', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = inviteMember($workspace, 'lim@example.test', 'admin', ['blocks.edit']);
    $this->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    $headers = ['Referer' => 'http://localhost:8000'];
    $id = (string) Str::uuid7();

    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertForbidden()->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);
    $this->postJson("/api/v1/admin/invitations/{$id}/resend", [], $headers)->assertForbidden();
    $this->deleteJson("/api/v1/admin/invitations/{$id}", [], $headers)->assertForbidden();

    $this->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    invitePost(['email' => 'bo@example.test', 'role' => 'user'])->assertForbidden();

    expect(invitationRows())->toBe([])->and(inviteMails())->toBe(0);
});

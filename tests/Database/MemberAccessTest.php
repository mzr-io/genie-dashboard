<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Access\Application\AccessSnapshot;
use App\Modules\Access\Application\ChangeMemberAccess;
use App\Modules\Access\Contracts\AccessChange;
use App\Modules\Access\Contracts\EditorNotAuthorized;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\LastUsersManageHolder;
use App\Modules\Access\Contracts\MemberAccess;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.22 against the real PostgreSQL: an Admin with `users.manage` changes another member's role and permissions
// under optimistic concurrency, the last-holder, self-edit, granter-cap and password rules hold, audit and outbox rows
// land in the same transaction, and the member's next request reads the new state.
const ACC_PASSWORD = 'editor-password-1';

beforeEach(fn () => $this->withoutVite());

/** A member of the Workspace; returns [user ID, membership ID]. */
function accMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], string $status = 'active'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(ACC_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

function accSignIn(int $user, string $workspaceId, string $area = 'admin'): void
{
    // A fresh session per sign-in: the session keeps the previous person's password hash.
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => $area]);
}

/** @return array{0: int, 1: string} the Admin editor (every permission), signed in */
function accEditor(string $workspaceId, ?array $permissions = null): array
{
    [$user, $membership] = accMember($workspaceId, 'ada@example.test', 'admin', $permissions ?? Permission::values());
    accSignIn($user, $workspaceId);

    return [$user, $membership];
}

function accPatch(string $membership, array $payload)
{
    return test()->patchJson("/api/v1/admin/members/{$membership}", $payload, ['Referer' => 'http://localhost:8000']);
}

function accRow(string $membership): array
{
    return Cluster::rows(Cluster::superuser(), 'select role, revision, status from workspace_memberships where id = ?', [$membership])[0];
}

function accHeld(string $membership): array
{
    $held = array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ?', [$membership]), 'permission');
    sort($held);

    return $held;
}

function accAudits(string $action): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at', [$action]);
}

function accEvents(string $type): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from outbox_events where type = ? order by occurred_at', [$type]);
}

function accNothingChanged(): void
{
    expect(accAudits('access.role.changed'))->toBe([])
        ->and(accAudits('access.permission.changed'))->toBe([])
        ->and(accEvents('access.role.changed'))->toBe([])
        ->and(accEvents('access.permission.changed'))->toBe([]);
}

it('changes a role from user to admin: revision plus one, audit and outbox of the same type, in one transaction', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test');

    $response = accPatch($bo, ['revision' => 1, 'role' => 'admin', 'confirm_password' => ACC_PASSWORD])->assertOk();

    expect($response->json('data'))->toMatchArray(['membership_id' => $bo, 'role' => 'admin', 'permissions' => [], 'revision' => 2])
        ->and(accRow($bo))->toMatchArray(['role' => 'admin', 'revision' => 2]);

    $audit = accAudits('access.role.changed');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('membership:'.$bo)
        ->and($audit[0]['security'])->toBeFalse()
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['role' => 'user', 'permission_count' => 0])
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['role' => 'admin', 'permission_count' => 0]);

    $event = accEvents('access.role.changed');
    expect($event)->toHaveCount(1)
        ->and(json_decode($event[0]['data'], true))->toEqual(['membership_id' => $bo, 'role' => 'admin', 'previous_role' => 'user'])
        ->and(accAudits('access.permission.changed'))->toBe([]);
});

it('requires the password to make someone an Admin, and a wrong one blocks the change', function (array $extra) {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test');

    $response = accPatch($bo, ['revision' => 1, 'role' => 'admin'] + $extra)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('confirm_password')
        ->and(accRow($bo))->toMatchArray(['role' => 'user', 'revision' => 1]);
    accNothingChanged();
})->with(['wrong' => [['confirm_password' => 'nope']], 'missing' => [[]]]);

it('does not clear the password throttle with a right password', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test');
    $wrong = fn () => accPatch($bo, ['revision' => 1, 'role' => 'admin', 'confirm_password' => 'wrong']);

    foreach (range(1, 5) as $ignored) {
        $wrong()->assertStatus(422);
    }

    // A right guess succeeds, but does not reset the count of wrong ones.
    accPatch($bo, ['revision' => 1, 'role' => 'admin', 'confirm_password' => ACC_PASSWORD])->assertOk();
    [, $cy] = accMember($workspace, 'cy@example.test');
    accPatch($cy, ['revision' => 1, 'role' => 'admin', 'confirm_password' => 'wrong'])->assertStatus(422);
    accPatch($cy, ['revision' => 1, 'role' => 'admin', 'confirm_password' => 'wrong'])->assertStatus(429);
    accPatch($cy, ['revision' => 1, 'role' => 'admin', 'confirm_password' => ACC_PASSWORD])->assertStatus(429);
});

it('grants and revokes permissions the editor holds, auditing the names as an enum list and never emails', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);

    accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit', 'blocks.publish'], 'confirm_password' => ACC_PASSWORD])->assertOk()
        ->assertJsonPath('data.permissions', ['blocks.edit', 'blocks.publish'])
        ->assertJsonPath('data.revision', 2);

    expect(accHeld($bo))->toBe(['blocks.edit', 'blocks.publish']);

    $audit = accAudits('access.permission.changed');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['role' => 'admin', 'permission_count' => 1, 'permissions' => ['blocks.edit']])
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['permission_count' => 2, 'permissions' => ['blocks.edit', 'blocks.publish']])
        ->and($audit[0]['after_state'])->not->toContain('@')
        ->and(accAudits('access.role.changed'))->toBe([]);

    $event = accEvents('access.permission.changed');
    expect($event)->toHaveCount(1)
        ->and(json_decode($event[0]['data'], true))->toEqual(['membership_id' => $bo, 'role' => 'admin', 'permission_count' => 2, 'previous_permission_count' => 1, 'added_count' => 1, 'removed_count' => 0]);

    // Revoke (the SECURITY DEFINER function deletes, role `app` cannot).
    accPatch($bo, ['revision' => 2, 'permissions' => [], 'confirm_password' => ACC_PASSWORD])->assertOk()->assertJsonPath('data.revision', 3);

    expect(accHeld($bo))->toBe([])
        ->and(accAudits('access.permission.changed'))->toHaveCount(2);
});

it('refuses a permission the editor does not hold, in both directions, with 403 access.permission_not_held', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace, ['users.manage', 'blocks.edit']);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['audit.view']);

    // Grant what the editor lacks.
    accPatch($bo, ['revision' => 1, 'permissions' => ['audit.view', 'blocks.publish'], 'confirm_password' => ACC_PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', ErrorCode::PermissionNotHeld->value);

    // Strip what the editor lacks (the symmetric cap).
    accPatch($bo, ['revision' => 1, 'permissions' => [], 'confirm_password' => ACC_PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', ErrorCode::PermissionNotHeld->value);

    // Downgrading strips every permission, so it is capped too.
    accPatch($bo, ['revision' => 1, 'role' => 'user', 'confirm_password' => ACC_PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', ErrorCode::PermissionNotHeld->value);

    expect(accHeld($bo))->toBe(['audit.view'])
        ->and(accRow($bo))->toMatchArray(['role' => 'admin', 'revision' => 1]);
    accNothingChanged();

    // What the editor holds still works.
    accPatch($bo, ['revision' => 1, 'permissions' => ['audit.view', 'blocks.edit'], 'confirm_password' => ACC_PASSWORD])->assertOk();
});

it('reads the editor\'s permissions per request: a permission revoked meanwhile caps the next change', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin');

    Cluster::superuser()->prepare("DELETE FROM membership_permissions WHERE membership_id = ? AND permission = 'blocks.publish'")->execute([$editor]);

    accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.publish'], 'confirm_password' => ACC_PASSWORD])->assertForbidden();
    accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit'], 'confirm_password' => ACC_PASSWORD])->assertOk();
});

it('refuses permissions on a User with 422 and clears them when downgrading', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $user] = accMember($workspace, 'bo@example.test');
    [, $admin] = accMember($workspace, 'cy@example.test', 'admin', ['blocks.edit', 'audit.view']);

    accPatch($user, ['revision' => 1, 'permissions' => ['blocks.edit'], 'confirm_password' => ACC_PASSWORD])->assertStatus(422)->assertJsonStructure(['errors' => ['permissions']]);
    accPatch($admin, ['revision' => 1, 'role' => 'user', 'permissions' => ['blocks.edit'], 'confirm_password' => ACC_PASSWORD])->assertStatus(422);

    expect(accHeld($user))->toBe([])
        ->and(accHeld($admin))->toBe(['audit.view', 'blocks.edit']);

    accPatch($admin, ['revision' => 1, 'role' => 'user', 'confirm_password' => ACC_PASSWORD])->assertOk()
        ->assertJsonPath('data.role', 'user')->assertJsonPath('data.permissions', []);

    expect(accHeld($admin))->toBe([])
        ->and(accRow($admin))->toMatchArray(['role' => 'user', 'revision' => 2])
        ->and(accAudits('access.role.changed'))->toHaveCount(1)
        ->and(accAudits('access.permission.changed'))->toHaveCount(1);
});

it('lets one of two holders lose users.manage over HTTP', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace, ['users.manage', 'blocks.edit']);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['users.manage']);

    accPatch($bo, ['revision' => 1, 'permissions' => [], 'confirm_password' => ACC_PASSWORD])->assertOk();

    expect(accHeld($bo))->toBe([]);
});

it('refuses taking users.manage from, or downgrading, the last active Admin holder (the rule itself, under the lock)', function (?string $role, ?array $permissions) {
    $workspace = Cluster::workspace('Acme');
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['users.manage']);
    // Not an active Admin holder: a User with a stale row, and a suspended Admin with the permission.
    accMember($workspace, 'stale@example.test', 'user', ['users.manage']);
    accMember($workspace, 'gone@example.test', 'admin', ['users.manage'], 'suspended');
    $before = accRow($bo);
    $check = new ReflectionMethod(ChangeMemberAccess::class, 'assertNotLastHolder');
    $service = app(ChangeMemberAccess::class);
    $snapshot = new AccessSnapshot('admin', 'active', 1, ['users.manage']);

    $run = fn () => app(WorkspaceTransaction::class)->run($workspace, fn () => $check->invoke($service, $workspace, $bo, $snapshot, $role ?? 'admin', $permissions ?? ['users.manage']));

    expect($run)->toThrow(LastUsersManageHolder::class)
        ->and(accRow($bo))->toBe($before);

    // With another active Admin holder the same change is fine.
    accMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    expect($run)->not->toThrow(LastUsersManageHolder::class);
})->with([
    'remove the permission' => [null, []],
    'downgrade' => ['user', null],
]);

it('refuses an editor who is no longer an active Admin holding users.manage, seen under the lock', function () {
    $workspace = Cluster::workspace('Acme');
    accMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    [$stale, $staleMembership] = accMember($workspace, 'stale@example.test', 'user', ['users.manage']);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['users.manage']);
    $editor = new MemberEditor($stale, $staleMembership, $workspace);

    $call = fn () => app(WorkspaceTransaction::class)->run($workspace, fn () => app(MemberAccess::class)->update($editor, $bo, 1, null, [], fn (): bool => true));

    expect($call)->toThrow(EditorNotAuthorized::class)
        ->and(accHeld($bo))->toBe(['users.manage']);
    accNothingChanged();
});

it('answers 403 access.not_authorized, 409 for the last holder, and audits each, when the service refuses so', function (Throwable $thrown, int $status, string $code, string $reason) {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    $this->app->instance(MemberAccess::class, new class($thrown) implements MemberAccess
    {
        public function __construct(private Throwable $thrown) {}

        public function update(MemberEditor $editor, string $membershipId, int $revision, ?string $role, ?array $permissions, Closure $confirmPassword): AccessChange
        {
            throw $this->thrown;
        }
    });

    accPatch($bo, ['revision' => 1, 'role' => 'user'])->assertStatus($status)->assertJsonPath('error.code', $code);

    $audit = accAudits('access.admin.denied');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['security'])->toBeTrue()
        ->and($audit[0]['actor'])->toBe($ada)
        ->and($audit[0]['subject'])->toBe('membership:'.$bo)
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['reason' => $reason, 'route' => 'api.admin.members.update'])
        ->and($audit[0]['after_state'])->not->toContain('@');
})->with([
    'not authorised' => [fn () => new EditorNotAuthorized, 403, 'access.not_authorized', 'not_authorized'],
    'last holder' => [fn () => new LastUsersManageHolder, 409, 'access.last_users_manage_holder', 'last_users_manage_holder'],
]);

it('records refused self edits, permissions not held and wrong passwords as access.admin.denied security events that outlive the rollback', function () {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = accEditor($workspace, ['users.manage', 'blocks.edit']);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['audit.view']);
    $reasons = fn () => array_map(fn (array $row): array => [$row['subject'], json_decode($row['after_state'], true)['reason']], accAudits('access.admin.denied'));

    accPatch($ada, ['revision' => 1, 'role' => 'user'])->assertForbidden();
    accPatch($bo, ['revision' => 1, 'permissions' => ['audit.view', 'blocks.publish'], 'confirm_password' => ACC_PASSWORD])->assertForbidden();
    accPatch($bo, ['revision' => 1, 'permissions' => ['audit.view', 'blocks.edit'], 'confirm_password' => 'wrong'])->assertStatus(422);

    expect($reasons())->toBe([
        ['membership:'.$ada, 'self_change'],
        ['membership:'.$bo, 'permission_not_held'],
        ['membership:'.$bo, 'wrong_password'],
    ]);
    // The changes themselves rolled back.
    expect(accHeld($bo))->toBe(['audit.view']);
    accNothingChanged();
});

it('treats a missing password as a field error that is neither a throttle hit nor an audited wrong guess', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);

    foreach (range(1, 8) as $ignored) {
        accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit', 'audit.view']])->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);
    }

    expect(accAudits('access.admin.denied'))->toBe([]);
    // Not throttled: the right password still works.
    accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => ACC_PASSWORD])->assertOk();
    // A non-string password is a validation error too.
    accPatch($bo, ['revision' => 2, 'permissions' => [], 'confirm_password' => ['x']])->assertStatus(422);
});

it('blocks a permissions-only change and an Admin-with-permissions downgrade without or with a wrong password: 422, nothing changed', function (array $change, array $extra) {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    $before = accRow($bo);

    $response = accPatch($bo, ['revision' => 1] + $change + $extra)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('confirm_password')
        ->and(accRow($bo))->toBe($before)
        ->and(accHeld($bo))->toBe(['blocks.edit']);
    accNothingChanged();
})->with([
    'permissions only, missing' => [['permissions' => ['blocks.edit', 'audit.view']], []],
    'permissions only, wrong' => [['permissions' => ['blocks.edit', 'audit.view']], ['confirm_password' => 'nope']],
    'downgrade, missing' => [['role' => 'user'], []],
    'downgrade, wrong' => [['role' => 'user'], ['confirm_password' => 'nope']],
]);

it('refuses to edit a member whose membership is not active: 422 membership_inactive, nothing changed', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit'], 'suspended');

    accPatch($bo, ['revision' => 1, 'permissions' => [], 'confirm_password' => ACC_PASSWORD])->assertStatus(422)->assertJsonPath('reason', 'membership_inactive');

    expect(accHeld($bo))->toBe(['blocks.edit'])
        ->and(accRow($bo)['revision'])->toBe(1);
    accNothingChanged();
});

it('writes nothing and answers 404 when the membership UPDATE touches no row', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    $su = Cluster::superuser();
    $su->exec('CREATE FUNCTION acc_skip_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
    $su->exec('CREATE TRIGGER acc_skip BEFORE UPDATE ON workspace_memberships FOR EACH ROW EXECUTE FUNCTION acc_skip_update()');

    try {
        accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => ACC_PASSWORD])->assertNotFound();
    } finally {
        $su->exec('DROP TRIGGER acc_skip ON workspace_memberships');
        $su->exec('DROP FUNCTION acc_skip_update()');
    }

    expect(accHeld($bo))->toBe(['blocks.edit'])
        ->and(accRow($bo)['revision'])->toBe(1);
    accNothingChanged();
});

it('rejects non-catalogue values, JSON nulls and permissions on a User in the function itself, as role app', function (string $json, string $role) {
    $workspace = Cluster::workspace('Acme');
    [, $member] = accMember($workspace, 'ada@example.test', $role, $role === 'admin' ? ['blocks.edit'] : []);
    $app = Cluster::directApp();

    expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$member}', '{$json}'::jsonb)")))->toThrow(PDOException::class)
        ->and(accHeld($member))->toBe($role === 'admin' ? ['blocks.edit'] : []);
})->with([
    'a null' => ['[null]', 'admin'],
    'a number' => ['[1]', 'admin'],
    'an object' => ['[{}]', 'admin'],
    'outside the catalogue' => ['["nope"]', 'admin'],
    'a mix' => ['["blocks.edit", null]', 'admin'],
    'not an array' => ['{"a": 1}', 'admin'],
    'a set on a user' => ['["blocks.edit"]', 'user'],
]);

it('lets the function clear the set of a User and set a catalogue set on an Admin', function () {
    $workspace = Cluster::workspace('Acme');
    [, $user] = accMember($workspace, 'bo@example.test');
    [, $admin] = accMember($workspace, 'ada@example.test', 'admin');
    $app = Cluster::directApp();

    Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$user}', '[]'::jsonb)"));
    Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$admin}', '[\"blocks.edit\", \"blocks.edit\"]'::jsonb)"));

    expect(accHeld($user))->toBe([])
        ->and(accHeld($admin))->toBe(['blocks.edit']);
});

it('refuses an edit of your own row with 403 access.self_change_forbidden', function () {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = accEditor($workspace);

    foreach ([['role' => 'user'], ['permissions' => []]] as $change) {
        accPatch($ada, ['revision' => 1, 'confirm_password' => ACC_PASSWORD] + $change)
            ->assertForbidden()->assertJsonPath('error.code', ErrorCode::SelfChangeForbidden->value);
    }

    $all = Permission::values();
    sort($all);

    expect(accRow($ada))->toMatchArray(['role' => 'admin', 'revision' => 1])
        ->and(accHeld($ada))->toBe($all);
    accNothingChanged();
});

it('returns 409 with the current state for a stale revision and changes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);

    accPatch($bo, ['revision' => 1, 'permissions' => ['blocks.edit', 'audit.view'], 'confirm_password' => ACC_PASSWORD])->assertOk();

    // A second Admin still holding revision 1.
    $stale = accPatch($bo, ['revision' => 1, 'role' => 'user', 'confirm_password' => ACC_PASSWORD])->assertStatus(409);

    expect($stale->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($stale->json('current'))->toBe(['role' => 'admin', 'permissions' => ['audit.view', 'blocks.edit'], 'revision' => 2])
        ->and(accRow($bo))->toMatchArray(['role' => 'admin', 'revision' => 2])
        ->and(accAudits('access.role.changed'))->toBe([])
        ->and(accAudits('access.permission.changed'))->toHaveCount(1);
});

it('is a 200 no-op without audit or revision change for the same role and set', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    [, $cy] = accMember($workspace, 'cy@example.test');

    // No password needed: nothing changes.
    accPatch($bo, ['revision' => 1, 'role' => 'admin', 'permissions' => ['blocks.edit']])->assertOk()->assertJsonPath('data.revision', 1);
    accPatch($cy, ['revision' => 1, 'role' => 'user'])->assertOk()->assertJsonPath('data.revision', 1);
    accPatch($cy, ['revision' => 1, 'permissions' => []])->assertOk()->assertJsonPath('data.revision', 1);

    expect(accRow($bo)['revision'])->toBe(1)
        ->and(accRow($cy)['revision'])->toBe(1);
    accNothingChanged();
});

it('applies a downgrade on the next request: the member is denied in the Admin area at once', function () {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [$boUser, $bo] = accMember($workspace, 'bo@example.test', 'admin', ['users.manage']);

    // Bo is in the Admin area and allowed.
    accSignIn($boUser, $workspace);
    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertOk();

    [$adaUser] = [Cluster::rows(Cluster::superuser(), "select user_id from workspace_memberships where role = 'admin' and id <> ?", [$bo])[0]['user_id']];
    accSignIn((int) $adaUser, $workspace);
    accPatch($bo, ['revision' => 1, 'role' => 'user', 'confirm_password' => ACC_PASSWORD])->assertOk();

    accSignIn($boUser, $workspace);
    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertForbidden()
        ->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);
});

it('answers 404 for another Workspace\'s membership and for a malformed id', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    accEditor($acme);
    [, $foreign] = accMember($other, 'zed@example.test', 'admin', ['blocks.edit']);

    accPatch($foreign, ['revision' => 1, 'permissions' => [], 'confirm_password' => ACC_PASSWORD])->assertNotFound();
    accPatch('not-a-uuid', ['revision' => 1, 'role' => 'user'])->assertNotFound();

    expect(accHeld($foreign))->toBe(['blocks.edit'])
        ->and(accRow($foreign))->toMatchArray(['role' => 'admin', 'revision' => 1]);
});

it('validates the body: revision required, role and permissions from closed sets', function (array $body) {
    $workspace = Cluster::workspace('Acme');
    accEditor($workspace);
    [, $bo] = accMember($workspace, 'bo@example.test');

    accPatch($bo, $body)->assertStatus(422)->assertJsonStructure(['errors']);
    accNothingChanged();
})->with([
    'no revision' => [['role' => 'admin']],
    'zero revision' => [['revision' => 0]],
    'bad role' => [['revision' => 1, 'role' => 'owner']],
    'bad permission' => [['revision' => 1, 'permissions' => ['everything']]],
]);

it('lets the function replace permissions for app only inside the transaction Workspace', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $mine] = accMember($acme, 'ada@example.test', 'admin', ['blocks.edit', 'audit.view']);
    [, $foreign] = accMember($other, 'zed@example.test', 'admin', ['blocks.edit']);
    $app = Cluster::directApp();

    // Role app has no DELETE on the table itself ...
    expect(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec('delete from membership_permissions')))->toThrow(PDOException::class, 'permission denied');

    // ... but the function replaces the set inside its own Workspace,
    Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$mine}', '[\"audit.view\", \"users.manage\"]'::jsonb)"));
    expect(accHeld($mine))->toBe(['audit.view', 'users.manage']);

    // refuses another Workspace's membership and an unset context, and only the catalogue is accepted.
    expect(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$foreign}', '[]'::jsonb)")))->toThrow(PDOException::class)
        ->and(fn () => $app->exec("select access_set_membership_permissions('{$mine}', '[]'::jsonb)"))->toThrow(PDOException::class)
        ->and(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec("select access_set_membership_permissions('{$mine}', '[\"nope\"]'::jsonb)")))->toThrow(PDOException::class)
        ->and(accHeld($foreign))->toBe(['blocks.edit'])
        ->and(accHeld($mine))->toBe(['audit.view', 'users.manage']);
});

it('keeps the owner, grants and revision default of the new objects', function () {
    Cluster::migrateOnce();
    $function = Cluster::rows(Cluster::superuser(), "select pg_get_userbyid(proowner) as owner, prosecdef from pg_proc where proname = 'access_set_membership_permissions'");
    $column = Cluster::rows(Cluster::superuser(), "select is_nullable, column_default from information_schema.columns where table_name = 'workspace_memberships' and column_name = 'revision'");

    expect($function)->toBe([['owner' => 'migrator', 'prosecdef' => true]])
        ->and($column)->toBe([['is_nullable' => 'NO', 'column_default' => '1']]);
});

it('returns false for the publish permission lookup of a member without blocks.publish and shares their own membership ID in the shell (the perm-publish reason is rendered in tests/js/gated-action.test.ts)', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = accMember($workspace, 'ada@example.test', 'admin', ['blocks.edit']);

    $held = app(MembershipPermissions::class)->forUser($user, $workspace);

    expect(in_array(Permission::BlocksPublish, $held, true))->toBeFalse()
        ->and(in_array(Permission::BlocksEdit, $held, true))->toBeTrue();

    // The shell's `can` map is what feeds GatedAction.
    accSignIn($user, $workspace);
    $shell = $this->get(route('admin.overview'), ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])
        ->assertOk()->json('props.shell');
    expect($shell['can']['blocks.publish'])->toBeFalse()
        ->and($shell['can']['blocks.edit'])->toBeTrue()
        ->and($shell['membership_id'])->toBe($membership);
});

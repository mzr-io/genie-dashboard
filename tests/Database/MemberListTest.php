<?php

use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberQuery;
use App\Modules\Access\Contracts\Permission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.20 against the real PostgreSQL: the Workspace's members and pending invitations through the Access view
// and SECURITY DEFINER function, under row-level security, with sorting, search, cursor paging and the 404.
beforeEach(function () {
    $this->withoutVite();
    config(['dashflow.tunables.lists.max_page_size.value' => null]);
});

/** @return array{0: int, 1: string} user ID and membership ID */
function listMember(string $workspaceId, string $email, string $name, string $role = 'user', string $status = 'active', ?string $lastActive = null, array $permissions = []): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, last_active_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status, $lastActive]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

function listInvitation(string $workspaceId, string $email, string $role = 'user', string $expires = '+2 days', bool $used = false): string
{
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO invitations (id, workspace_id, email, token_hash, role, expires_at, used_at, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now(), now())')
        ->execute([$id, $workspaceId, $email, hash('sha256', $id), $role, date('c', strtotime($expires)), $used ? date('c') : null, 'test']);

    return $id;
}

/** An Admin of the Workspace with `users.manage`, signed in to the Admin area. */
function listAdmin(string $workspaceId, string $email = 'admin@example.test', string $name = 'Ada Admin', array $permissions = ['users.manage']): string
{
    [$user, $membership] = listMember($workspaceId, $email, $name, 'admin', 'active', null, $permissions);
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return $membership;
}

function listGet(string $query = '')
{
    return test()->getJson('/api/v1/admin/members'.($query === '' ? '' : '?'.$query), ['Referer' => 'http://localhost:8000']);
}

it('lists the Workspace\'s members and pending invitations with name, email, role, status, groups and last active', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    listMember($workspace, 'bo@example.test', 'Bo User', 'user', 'active', '2026-10-01 09:30:00');
    listMember($workspace, 'cy@example.test', 'Cy Gone', 'user', 'suspended');
    $invitation = listInvitation($workspace, 'new@example.test', 'admin');

    $response = listGet('sort=email')->assertOk();
    $rows = collect($response->json('data'))->keyBy('email');

    expect($rows)->toHaveCount(4)
        ->and($rows['admin@example.test'])->toMatchArray(['name' => 'Ada Admin', 'role' => 'admin', 'status' => 'active', 'groups' => [], 'last_active_at' => null])
        ->and($rows['bo@example.test'])->toMatchArray(['name' => 'Bo User', 'role' => 'user', 'status' => 'active', 'groups' => [], 'last_active_at' => '2026-10-01T09:30:00Z'])
        ->and($rows['cy@example.test']['status'])->toBe('deactivated')
        ->and($rows['new@example.test'])->toMatchArray(['kind' => 'invitation', 'invitation_id' => $invitation, 'name' => '', 'role' => 'admin', 'status' => 'invited', 'groups' => [], 'last_active_at' => null])
        ->and($response->json('meta.total'))->toBe(4)
        ->and($response->json('meta.matched'))->toBe(4)
        ->and($response->json('meta.next_cursor'))->toBeNull()
        ->and($rows['new@example.test'])->not->toHaveKey('membership_id')
        ->and($rows['bo@example.test'])->toMatchArray(['kind' => 'member'])->not->toHaveKey('invitation_id')
        ->and(array_keys($rows['bo@example.test']))->toBe(['kind', 'membership_id', 'name', 'email', 'role', 'status', 'groups', 'last_active_at', 'permissions', 'revision'])
        ->and(array_keys($rows['new@example.test']))->toBe(['kind', 'invitation_id', 'name', 'email', 'role', 'status', 'groups', 'last_active_at'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('never lists a password hash, token or remember token', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    Cluster::superuser()->exec("UPDATE users SET remember_token = 'remember-secret'");
    listInvitation($workspace, 'new@example.test');

    $body = listGet()->assertOk()->getContent();

    expect($body)->not->toContain('not-a-real-hash')
        ->and($body)->not->toContain('remember-secret')
        ->and($body)->not->toContain('password')
        ->and($body)->not->toContain('token');
});

it('shows only the active Workspace: members and invitations of another Workspace never appear', function () {
    $mine = Cluster::workspace('Mine');
    $other = Cluster::workspace('Other');
    listAdmin($mine);
    listMember($other, 'eve@example.test', 'Eve Elsewhere');
    listInvitation($other, 'secret-invitee@example.test');
    listInvitation($mine, 'mine-invitee@example.test');

    $emails = array_column(listGet()->assertOk()->json('data'), 'email');

    expect($emails)->toEqualCanonicalizing(['admin@example.test', 'mine-invitee@example.test']);
});

it('leaves used and expired invitations out', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    listInvitation($workspace, 'used@example.test', 'user', '+2 days', true);
    listInvitation($workspace, 'expired@example.test', 'user', '-1 hour');
    listInvitation($workspace, 'pending@example.test');

    expect(array_column(listGet()->json('data'), 'email'))->toEqualCanonicalizing(['admin@example.test', 'pending@example.test']);
});

it('leaves revoked invitations out of the list and the function', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    $revoked = listInvitation($workspace, 'revoked@example.test');
    listInvitation($workspace, 'pending@example.test');
    Cluster::superuser()->prepare('UPDATE invitations SET revoked_at = now() WHERE id = ?')->execute([$revoked]);

    expect(array_column(listGet()->json('data'), 'email'))->toEqualCanonicalizing(['admin@example.test', 'pending@example.test']);
});

it('returns no invitations from the function when the Workspace context is unset, and only its own with it', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    listInvitation($a, 'a@example.test');
    listInvitation($b, 'b@example.test');

    $app = Cluster::directApp();
    expect(Cluster::rows($app, 'select * from access_workspace_invitations()'))->toBe([]);

    $emails = Cluster::inWorkspace($app, $a, fn ($pdo) => array_column(Cluster::rows($pdo, 'select email from access_workspace_invitations()'), 'email'));
    expect($emails)->toBe(['a@example.test']);

    // Nor does the view, with no context.
    expect(Cluster::rows($app, 'select * from access_workspace_members'))->toBe([]);
});

it('shows a Workspace with only its first Admin as one row, and an empty one as none', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);

    $response = listGet()->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.role'))->toBe('admin')
        ->and($response->json('meta.total'))->toBe(1);

    // No member and no invitation at all: an empty page (the client shows msg:list-empty).
    $empty = app(MemberDirectory::class)->page(Cluster::workspace('Empty'), new MemberQuery);
    expect($empty->rows)->toBe([])->and($empty->total)->toBe(0)->and($empty->matched)->toBe(0)->and($empty->nextCursor)->toBeNull();
});

it('sorts by every whitelisted column in both directions with a stable tie-break on the row id', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, 'admin@example.test', 'Mia Admin');
    listMember($workspace, 'b@example.test', 'bob', 'user', 'active', '2026-10-03 10:00:00');
    listMember($workspace, 'a@example.test', 'Alice', 'user', 'suspended', '2026-10-01 10:00:00');
    listMember($workspace, 'c@example.test', 'Alice', 'user', 'active', null);
    listInvitation($workspace, 'z@example.test', 'user');

    $order = fn (string $sort, string $direction): array => array_column(listGet("sort={$sort}&direction={$direction}")->assertOk()->json('data'), 'email');

    // Names compare case-insensitively; the two "Alice" rows tie and fall back to the row id.
    $byName = $order('name', 'asc');
    expect(array_slice($byName, 0, 1))->toBe(['z@example.test'])
        ->and(array_slice($byName, 1, 2))->toEqualCanonicalizing(['a@example.test', 'c@example.test'])
        ->and(array_slice($byName, 3))->toBe(['b@example.test', 'admin@example.test'])
        ->and($order('name', 'desc'))->toBe(array_reverse($byName))
        ->and($order('email', 'asc'))->toBe(['a@example.test', 'admin@example.test', 'b@example.test', 'c@example.test', 'z@example.test'])
        ->and($order('email', 'desc'))->toBe(['z@example.test', 'c@example.test', 'b@example.test', 'admin@example.test', 'a@example.test'])
        ->and(array_slice($order('last_active', 'desc'), 0, 2))->toBe(['b@example.test', 'a@example.test'])
        ->and(array_slice($order('last_active', 'desc'), 2))->toEqualCanonicalizing(['admin@example.test', 'c@example.test', 'z@example.test'])
        ->and(array_slice($order('last_active', 'asc'), 0, 3))->toEqualCanonicalizing(['admin@example.test', 'c@example.test', 'z@example.test'])
        ->and(array_slice($order('last_active', 'asc'), 3))->toBe(['a@example.test', 'b@example.test'])
        ->and($order('status', 'asc'))->not->toBe($order('status', 'desc'));

    $byRole = collect(listGet('sort=role&direction=asc')->json('data'))->pluck('role')->all();
    expect($byRole)->toBe(['admin', 'user', 'user', 'user', 'user']);

    $byStatus = collect(listGet('sort=status&direction=asc')->json('data'))->pluck('status')->all();
    expect($byStatus)->toBe(['active', 'active', 'active', 'deactivated', 'invited']);

    // Both orders are deterministic across calls.
    expect($order('role', 'asc'))->toBe($order('role', 'asc'))->and($order('last_active', 'asc'))->toBe($order('last_active', 'asc'));
});

it('ignores an unknown sort column instead of passing it to the database', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    listMember($workspace, 'bo@example.test', 'Bo');

    $response = listGet('sort='.rawurlencode('password; drop table users').'&direction=sideways')->assertOk();

    expect($response->json('meta.sort'))->toBe('name')
        ->and($response->json('meta.direction'))->toBe('asc')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from users')[0]['n'])->toBe(2);
});

it('searches name and email case-insensitively and escapes the wildcards', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, 'admin@example.test', 'Ada Admin');
    listMember($workspace, 'bo@example.test', 'Bo Smith');
    listMember($workspace, 'pct@example.test', '100% Real');
    listMember($workspace, 'under_score@example.test', 'Under');
    listMember($workspace, 'underXscore@example.test', 'Other');
    listInvitation($workspace, 'invitee-SMITH@example.test');

    $emails = fn (string $q): array => array_column(listGet('q='.rawurlencode($q))->assertOk()->json('data'), 'email');

    expect($emails('SMITH'))->toEqualCanonicalizing(['bo@example.test', 'invitee-SMITH@example.test'])
        ->and($emails('ada a'))->toBe(['admin@example.test'])
        ->and($emails('100%'))->toBe(['pct@example.test'])
        ->and($emails('%'))->toBe(['pct@example.test'])
        ->and($emails('under_score'))->toBe(['under_score@example.test'])
        ->and($emails('_'))->toEqualCanonicalizing(['under_score@example.test'])
        ->and($emails('nobody-at-all'))->toBe([]);

    $none = listGet('q=nobody-at-all')->json('meta');
    expect($none['matched'])->toBe(0)->and($none['total'])->toBe(6);
});

it('pages by cursor in a stable order, with no row repeated or skipped', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, 'admin@example.test', 'Ada');

    foreach (range(1, 20) as $n) {
        listMember($workspace, sprintf('user%02d@example.test', $n), $n % 4 === 0 ? 'Same Name' : sprintf('User %02d', $n), 'user', 'active', $n % 3 === 0 ? null : sprintf('2026-10-%02d 08:00:00', $n));
    }

    foreach (['name', 'email', 'role', 'status', 'last_active'] as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            $seen = [];
            $cursor = null;
            $pages = 0;

            do {
                $response = listGet("sort={$sort}&direction={$direction}".($cursor === null ? '' : '&cursor='.$cursor))->assertOk();
                $seen = [...$seen, ...array_map(fn ($row) => $row['membership_id'] ?? $row['invitation_id'], $response->json('data'))];
                $cursor = $response->json('meta.next_cursor');
                $pages++;
            } while ($cursor !== null && $pages < 10);

            expect($pages)->toBe(2, "{$sort} {$direction}: pages")
                ->and($seen)->toHaveCount(21)
                ->and(array_unique($seen))->toHaveCount(21, "{$sort} {$direction}: no repeats");
        }
    }
});

it('pages by the framework default of 15 while the cap is unset and clamps a requested size to a set cap', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);

    foreach (range(1, 30) as $n) {
        listMember($workspace, sprintf('user%02d@example.test', $n), sprintf('User %02d', $n));
    }

    // Unset: 15, and a requested size is ignored.
    expect(listGet()->json('data'))->toHaveCount(15)
        ->and(listGet('per_page=100')->json('data'))->toHaveCount(15)
        ->and(listGet('per_page=100')->json('meta.per_page'))->toBe(15);

    // Set: the request is honoured up to the cap.
    config(['dashflow.tunables.lists.max_page_size.value' => '10']);
    expect(listGet('per_page=100')->json('data'))->toHaveCount(10)
        ->and(listGet('per_page=4')->json('data'))->toHaveCount(4)
        ->and(listGet()->json('data'))->toHaveCount(10);
});

it('rejects a malformed cursor and one from another sort', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    foreach (range(1, 20) as $n) {
        listMember($workspace, "u{$n}@example.test", "User {$n}");
    }

    listGet('cursor=not-a-cursor')->assertStatus(422);

    $cursor = listGet('sort=name')->json('meta.next_cursor');
    listGet('sort=email&cursor='.$cursor)->assertStatus(422);
});

it('answers 404 for a member of another Workspace, a malformed ID and an invitation, and 200 for its own', function () {
    $mine = Cluster::workspace('Mine');
    $other = Cluster::workspace('Other');
    $admin = listAdmin($mine);
    [, $foreign] = listMember($other, 'eve@example.test', 'Eve');
    [, $own] = listMember($mine, 'bo@example.test', 'Bo');
    $invitation = listInvitation($mine, 'pending@example.test');
    $headers = ['Referer' => 'http://localhost:8000'];

    $this->getJson("/api/v1/admin/members/{$foreign}", $headers)->assertNotFound();
    $this->getJson('/api/v1/admin/members/not-a-uuid', $headers)->assertNotFound();
    $this->getJson("/api/v1/admin/members/{$invitation}", $headers)->assertNotFound();
    $this->getJson("/api/v1/admin/members/{$own}", $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.kind', 'member')->assertJsonPath('data.membership_id', $own)->assertJsonPath('data.email', 'bo@example.test')->assertJsonPath('data.groups', []);
    $this->getJson("/api/v1/admin/members/{$admin}", $headers)->assertOk();
});

it('proves the 404 against row-level security, not only the WHERE clause', function () {
    $mine = Cluster::workspace('Mine');
    $other = Cluster::workspace('Other');
    [, $foreign] = listMember($other, 'eve@example.test', 'Eve');

    $app = Cluster::directApp();
    $visible = Cluster::inWorkspace($app, $mine, fn ($pdo) => Cluster::rows($pdo, 'select id from access_workspace_members where id = ?', [$foreign]));
    expect($visible)->toBe([]);

    $withoutFilter = Cluster::inWorkspace($app, $mine, fn ($pdo) => Cluster::rows($pdo, 'select id from workspace_memberships'));
    expect($withoutFilter)->toBe([]);
});

it('denies the page and the API without users.manage with 403, perm-denied and an audit row', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, 'admin@example.test', 'Ada', ['blocks.edit']);
    Cache::flush();

    $this->get(route('admin.users.index'))->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('Forbidden'));

    listGet()->assertForbidden()->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);

    $routes = array_map(fn ($row) => json_decode($row['after_state'], true)['route'], Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'access.admin.denied'"));
    expect($routes)->toEqualCanonicalizing(['admin.users.index', 'api.admin.members']);
});

it('denies a person in the User area', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = listMember($workspace, 'bo@example.test', 'Bo', 'admin', 'active', null, Permission::values());
    $this->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'user']);

    listGet()->assertForbidden();
});

it('renders the User configuration page for an Admin with users.manage', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);

    $this->get(route('admin.users.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Users'));
});

it('owns access_workspace_invitations by migrator', function () {
    $owner = Cluster::rows(Cluster::superuser(), "select r.rolname from pg_proc p join pg_roles r on r.oid = p.proowner where p.proname = 'access_workspace_invitations'");

    expect(array_column($owner, 'rolname'))->toBe(['migrator']);
});

it('rejects a NUL byte or invalid UTF-8 in q and in a cursor with 422, never a 500', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    foreach (range(1, 20) as $n) {
        listMember($workspace, "u{$n}@example.test", "User {$n}");
    }

    listGet('q='.rawurlencode("a\0b"))->assertStatus(422);
    listGet('q=%FF%FE')->assertStatus(422);
    listGet('cursor=%FF')->assertStatus(422);

    $forge = fn (string $key, string $id) => rtrim(strtr(base64_encode(json_encode(['s' => 'name', 'd' => false, 'k' => $key, 'i' => $id])), '+/', '-_'), '=');
    listGet('sort=name&cursor='.$forge("a\0b", (string) Str::uuid7()))->assertStatus(422);
    // A hand-made cursor with invalid UTF-8 bytes in the key (not valid JSON, so refused the same way).
    listGet('sort=name&cursor='.rtrim(strtr(base64_encode("{\"s\":\"name\",\"d\":false,\"k\":\"\xFF\",\"i\":\"".Str::uuid7().'"}'), '+/', '-_'), '='))->assertStatus(422);
    listGet('sort=name&cursor='.$forge('fine', (string) Str::uuid7()))->assertOk();
});

it('falls back to 15 rows for an unusable cap and refuses an unusable per_page when a cap is set', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    foreach (range(1, 20) as $n) {
        listMember($workspace, sprintf('u%02d@example.test', $n), sprintf('User %02d', $n));
    }

    foreach (['0', 'abc', '-3', '12345678901234567890'] as $cap) {
        config(['dashflow.tunables.lists.max_page_size.value' => $cap]);
        expect(listGet('per_page=3')->json('data'))->toHaveCount(15, "cap {$cap}");
    }

    config(['dashflow.tunables.lists.max_page_size.value' => '10']);
    foreach (['0', '-1', 'abc'] as $size) {
        listGet("per_page={$size}")->assertStatus(422);
    }
});

it('refuses a cursor whose direction differs from the request, both ways', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace);
    foreach (range(1, 20) as $n) {
        listMember($workspace, "u{$n}@example.test", "User {$n}");
    }

    $asc = listGet('sort=name&direction=asc')->json('meta.next_cursor');
    $desc = listGet('sort=name&direction=desc')->json('meta.next_cursor');

    listGet('sort=name&direction=desc&cursor='.$asc)->assertStatus(422);
    listGet('sort=name&direction=asc&cursor='.$desc)->assertStatus(422);
});

it('concatenates its pages into exactly the single full sorted list for every sort and direction', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, 'admin@example.test', 'Ada');
    foreach (range(1, 23) as $n) {
        listMember($workspace, sprintf('user%02d@example.test', $n), $n % 4 === 0 ? 'Same Name' : sprintf('User %02d', $n), $n % 5 === 0 ? 'admin' : 'user', $n % 7 === 0 ? 'suspended' : 'active', $n % 3 === 0 ? null : sprintf('2026-10-%02d 08:00:00', $n));
    }
    listInvitation($workspace, 'pending@example.test');

    config(['dashflow.tunables.lists.max_page_size.value' => '100']);
    $ids = fn ($response) => array_map(fn ($row) => $row['membership_id'] ?? $row['invitation_id'], $response->json('data'));

    foreach (['name', 'email', 'role', 'status', 'last_active'] as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            $full = $ids(listGet("sort={$sort}&direction={$direction}&per_page=100")->assertOk());
            $paged = [];
            $cursor = null;

            do {
                $response = listGet("sort={$sort}&direction={$direction}&per_page=7".($cursor === null ? '' : '&cursor='.$cursor))->assertOk();
                $paged = [...$paged, ...$ids($response)];
                $cursor = $response->json('meta.next_cursor');
            } while ($cursor !== null);

            expect($full)->toHaveCount(25)->and($paged)->toBe($full, "{$sort} {$direction}");
        }
    }
});

it('carries the exact sorted permissions and the real revision on member rows, and none on invitation rows', function () {
    $workspace = Cluster::workspace('Acme');
    listAdmin($workspace, permissions: ['users.manage', 'blocks.edit', 'audit.view']);
    [, $bo] = listMember($workspace, 'bo@example.test', 'Bo Admin', 'admin', 'active', null, ['templates.manage', 'blocks.publish']);
    listMember($workspace, 'cy@example.test', 'Cy User', 'user');
    Cluster::superuser()->prepare('UPDATE workspace_memberships SET revision = 7 WHERE id = ?')->execute([$bo]);
    listInvitation($workspace, 'new@example.test', 'admin');

    $rows = collect(listGet('sort=email')->assertOk()->json('data'))->keyBy('email');

    expect($rows['admin@example.test']['permissions'])->toBe(['audit.view', 'blocks.edit', 'users.manage'])
        ->and($rows['admin@example.test']['revision'])->toBe(1)
        ->and($rows['bo@example.test']['permissions'])->toBe(['blocks.publish', 'templates.manage'])
        ->and($rows['bo@example.test']['revision'])->toBe(7)
        ->and($rows['cy@example.test']['permissions'])->toBe([])
        ->and($rows['new@example.test'])->not->toHaveKey('permissions')->not->toHaveKey('revision');

    // The single-member read carries them too.
    listGet()->assertOk();
    $this->getJson("/api/v1/admin/members/{$bo}", ['Referer' => 'http://localhost:8000'])->assertOk()
        ->assertJsonPath('data.permissions', ['blocks.publish', 'templates.manage'])->assertJsonPath('data.revision', 7);
});

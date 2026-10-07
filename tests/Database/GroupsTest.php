<?php

use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Infrastructure\SqlMemberDirectory;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.23 against the real PostgreSQL: an Admin with `users.manage` creates, renames and deletes groups and changes
// their members; audit and outbox rows land in the same transaction; another Workspace's groups and members are
// invisible; removals go through the SECURITY DEFINER functions; and the member list shows each member's groups.
beforeEach(fn () => $this->withoutVite());

/** A member of the Workspace; returns [user ID, membership ID]. */
function grpMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], string $status = 'active', ?string $name = null): array
{
    $user = Cluster::user($email);

    if ($name !== null) {
        Cluster::superuser()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user]);
    }

    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

/** The Admin editor holding `users.manage`, signed in; returns [user ID, membership ID]. */
function grpAdmin(string $workspaceId, array $permissions = ['users.manage']): array
{
    [$user, $membership] = grpMember($workspaceId, 'ada@example.test', 'admin', $permissions, name: 'Ada Admin');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

const GRP_HEADERS = ['Referer' => 'http://localhost:8000'];

function grpCreate(string $name)
{
    return test()->postJson('/api/v1/admin/groups', ['name' => $name], GRP_HEADERS);
}

function grpAdd(string $group, string $membership)
{
    return test()->postJson("/api/v1/admin/groups/{$group}/members/{$membership}", [], GRP_HEADERS);
}

function grpRemove(string $group, string $membership)
{
    return test()->deleteJson("/api/v1/admin/groups/{$group}/members/{$membership}", [], GRP_HEADERS);
}

function grpGroups(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from user_groups order by name');
}

function grpPairs(string $group): array
{
    return array_column(Cluster::rows(Cluster::superuser(), 'select membership_id from group_members where group_id = ? order by membership_id', [$group]), 'membership_id');
}

function grpAudits(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'access.group.changed' order by occurred_at, id");
}

function grpEvents(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'access.group.changed' order by occurred_at, id");
}

it('creates a group: audited and emitted with IDs and enums only, never the name', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = grpAdmin($workspace);

    $response = grpCreate('  Sales team ')->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $response->json('data.group_id');

    expect($response->json('data'))->toMatchArray(['name' => 'Sales team', 'member_count' => 0, 'members' => []])
        ->and(grpGroups())->toHaveCount(1)
        ->and(grpGroups()[0])->toMatchArray(['id' => $id, 'workspace_id' => $workspace, 'name' => 'Sales team']);

    $audit = grpAudits();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('group:'.$id)
        ->and($audit[0]['security'])->toBeFalse();

    $after = json_decode($audit[0]['after_state'], true);
    expect($after)->toMatchArray(['group_id' => $id, 'change' => 'created', 'member_count' => 0])
        ->and($after['name'])->not->toBe('Sales team')
        ->and($audit[0]['after_state'])->not->toContain('Sales team');

    $events = grpEvents();
    expect($events)->toHaveCount(1)
        ->and(json_decode($events[0]['data'], true))->toEqual(['group_id' => $id, 'change' => 'created'])
        ->and($events[0]['subject'])->toBe('group:'.$id)
        ->and($events[0]['data'])->not->toContain('Sales');
});

it('refuses a duplicate name that differs by case or spaces with a 422 field error and creates nothing', function (string $duplicate) {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    grpCreate('Sales Team')->assertCreated();

    $response = grpCreate($duplicate)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('name')
        ->and($response->json('reason'))->toBe('name_taken')
        ->and(grpGroups())->toHaveCount(1)
        ->and(grpAudits())->toHaveCount(1);
})->with(['case' => ['sales team'], 'spaces' => ['  SALES TEAM  ']]);

it('allows the same name in another Workspace', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedGroup($other, 'Sales Team');
    grpAdmin($acme);

    grpCreate('Sales Team')->assertCreated();

    expect(grpGroups())->toHaveCount(2);
});

it('validates the name: required, 64 characters at most, no control characters', function (mixed $name) {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);

    test()->postJson('/api/v1/admin/groups', ['name' => $name], GRP_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

    expect(grpGroups())->toBe([]);
})->with([
    'missing' => [null],
    'blank' => ['   '],
    'too long' => [str_repeat('a', 65)],
    'control character' => ["Sales\x07Team"],
    'newline' => ["Sales\nTeam"],
    'not a string' => [['x']],
]);

it('accepts a name of exactly 64 characters', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);

    grpCreate(str_repeat('é', 64))->assertCreated();
});

it('adds a member, shows the group on the member in the list at once, and treats an existing pair as a 200 no-op', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = grpAdmin($workspace);
    [, $bo] = grpMember($workspace, 'bo@example.test', name: 'Bo User');
    $group = grpCreate('Sales')->json('data.group_id');

    $response = grpAdd($group, $bo)->assertOk();
    expect($response->json('data.member_count'))->toBe(1)
        ->and($response->json('data.members.0'))->toEqual(['membership_id' => $bo, 'name' => 'Bo User', 'email' => 'bo@example.test', 'status' => 'active'])
        ->and(grpPairs($group))->toBe([$bo]);

    // The next request reflects it: the member list names the group (nothing is cached).
    $list = test()->getJson('/api/v1/admin/members', GRP_HEADERS)->assertOk()->json('data');
    $rows = array_column($list, null, 'email');
    expect($rows['bo@example.test']['groups'])->toBe([['id' => $group, 'name' => 'Sales']])
        ->and($rows['ada@example.test']['groups'])->toBe([]);

    $audit = grpAudits();
    expect($audit)->toHaveCount(2);
    $last = $audit[1];
    expect(json_decode($last['after_state'], true))->toMatchArray(['group_id' => $group, 'change' => 'member_added', 'membership_id' => $bo, 'member_count' => 1])
        ->and(json_decode($last['before_state'], true))->toMatchArray(['member_count' => 0])
        ->and($last['actor'])->toBe($editor);
    expect(json_decode(grpEvents()[1]['data'], true))->toEqual(['group_id' => $group, 'change' => 'member_added', 'membership_id' => $bo]);

    // Adding again is a 200 no-op: one pair, no further audit or event.
    grpAdd($group, $bo)->assertOk()->assertJsonPath('data.member_count', 1);
    expect(grpPairs($group))->toBe([$bo])->and(grpAudits())->toHaveCount(2)->and(grpEvents())->toHaveCount(2);
});

it('orders a member\'s groups by name in the list', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    [, $bo] = grpMember($workspace, 'bo@example.test');
    $zeta = grpCreate('zeta')->json('data.group_id');
    $alpha = grpCreate('Alpha')->json('data.group_id');
    $mid = grpCreate('Mid')->json('data.group_id');

    foreach ([$zeta, $alpha, $mid] as $group) {
        grpAdd($group, $bo)->assertOk();
    }

    $row = test()->getJson('/api/v1/admin/members/'.$bo, GRP_HEADERS)->assertOk()->json('data');

    expect(array_column($row['groups'], 'name'))->toBe(['Alpha', 'Mid', 'zeta']);
});

it('removes a member (audited), and removing an absent pair is a 200 no-op', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    [, $bo] = grpMember($workspace, 'bo@example.test');
    [, $cy] = grpMember($workspace, 'cy@example.test');
    $group = grpCreate('Sales')->json('data.group_id');
    grpAdd($group, $bo)->assertOk();
    grpAdd($group, $cy)->assertOk();

    grpRemove($group, $bo)->assertOk()->assertJsonPath('data.member_count', 1);
    expect(grpPairs($group))->toBe([$cy]);

    $audit = grpAudits();
    expect($audit)->toHaveCount(4)
        ->and(json_decode($audit[3]['after_state'], true))->toMatchArray(['change' => 'member_removed', 'membership_id' => $bo, 'member_count' => 1])
        ->and(json_decode($audit[3]['before_state'], true))->toMatchArray(['member_count' => 2]);

    grpRemove($group, $bo)->assertOk()->assertJsonPath('data.member_count', 1);
    expect(grpAudits())->toHaveCount(4)->and(grpEvents())->toHaveCount(4);

    // The list no longer names the group on the member.
    $row = test()->getJson('/api/v1/admin/members/'.$bo, GRP_HEADERS)->assertOk()->json('data');
    expect($row['groups'])->toBe([]);
});

it('answers 404 for a membership of another Workspace and for a group of another Workspace', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    grpAdmin($acme);
    [, $foreignMember] = grpMember($other, 'zed@example.test');
    $foreignGroup = Cluster::seedGroup($other, 'Theirs');
    [, $bo] = grpMember($acme, 'bo@example.test');
    $group = grpCreate('Mine')->json('data.group_id');

    grpAdd($group, $foreignMember)->assertNotFound();
    // Removing a membership that is not in this Workspace is a no-op: it is not in the group.
    grpRemove($group, $foreignMember)->assertOk()->assertJsonPath('data.member_count', 0);
    grpAdd($foreignGroup, $bo)->assertNotFound();
    grpRemove($foreignGroup, $bo)->assertNotFound();
    test()->patchJson("/api/v1/admin/groups/{$foreignGroup}", ['name' => 'Taken over'], GRP_HEADERS)->assertNotFound();
    test()->deleteJson("/api/v1/admin/groups/{$foreignGroup}", [], GRP_HEADERS)->assertNotFound();
    grpAdd('not-a-uuid', $bo)->assertNotFound();
    grpAdd($group, 'not-a-uuid')->assertNotFound();

    expect(grpPairs($group))->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select name from user_groups where id = ?', [$foreignGroup]))->toBe([['name' => 'Theirs']])
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from group_members')[0]['n'])->toBe(0)
        ->and(grpAudits())->toHaveCount(1)
        ->and(grpEvents())->toHaveCount(1);
});

it('renames a group: audited, a duplicate is a 422, the same name is a no-op', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    $sales = grpCreate('Sales')->json('data.group_id');
    grpCreate('Support')->assertCreated();

    $response = test()->patchJson("/api/v1/admin/groups/{$sales}", ['name' => ' Revenue '], GRP_HEADERS)->assertOk();
    expect($response->json('data.name'))->toBe('Revenue')
        ->and(Cluster::rows(Cluster::superuser(), 'select name from user_groups where id = ?', [$sales]))->toBe([['name' => 'Revenue']]);

    $audit = grpAudits();
    expect($audit)->toHaveCount(3)
        ->and(json_decode($audit[2]['after_state'], true))->toMatchArray(['group_id' => $sales, 'change' => 'renamed'])
        ->and($audit[2]['before_state'])->not->toContain('Sales')
        ->and($audit[2]['after_state'])->not->toContain('Revenue')
        ->and(json_decode(grpEvents()[2]['data'], true))->toEqual(['group_id' => $sales, 'change' => 'renamed']);

    $duplicate = test()->patchJson("/api/v1/admin/groups/{$sales}", ['name' => 'support'], GRP_HEADERS)->assertStatus(422);
    expect($duplicate->json('errors'))->toHaveKey('name')
        ->and(Cluster::rows(Cluster::superuser(), 'select name from user_groups where id = ?', [$sales]))->toBe([['name' => 'Revenue']]);

    // A change of case only is allowed (it is the group's own name), and the same name changes nothing.
    test()->patchJson("/api/v1/admin/groups/{$sales}", ['name' => 'REVENUE'], GRP_HEADERS)->assertOk();
    test()->patchJson("/api/v1/admin/groups/{$sales}", ['name' => 'REVENUE'], GRP_HEADERS)->assertOk();
    expect(grpAudits())->toHaveCount(4);
});

it('deletes a group with its memberships only, audited, and keeps the members', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    [, $bo] = grpMember($workspace, 'bo@example.test');
    [, $cy] = grpMember($workspace, 'cy@example.test');
    $group = grpCreate('Sales')->json('data.group_id');
    $keep = grpCreate('Keep')->json('data.group_id');
    grpAdd($group, $bo)->assertOk();
    grpAdd($group, $cy)->assertOk();
    grpAdd($keep, $bo)->assertOk();

    test()->deleteJson("/api/v1/admin/groups/{$group}", [], GRP_HEADERS)->assertNoContent();

    expect(array_column(grpGroups(), 'id'))->toBe([$keep])
        ->and(grpPairs($group))->toBe([])
        ->and(grpPairs($keep))->toBe([$bo])
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(3);

    $audit = array_values(array_filter(grpAudits(), fn (array $row): bool => json_decode($row['after_state'], true)['change'] === 'deleted'));
    expect($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['group_id' => $group, 'member_count' => 2])
        ->and($audit[0]['before_state'])->not->toContain('Sales')
        ->and($audit[0]['subject'])->toBe('group:'.$group);

    test()->deleteJson("/api/v1/admin/groups/{$group}", [], GRP_HEADERS)->assertNotFound();
});

it('keeps a deactivated member in their groups, shows them Deactivated, and allows adding one', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    [, $bo] = grpMember($workspace, 'bo@example.test', name: 'Bo User');
    $group = grpCreate('Sales')->json('data.group_id');
    grpAdd($group, $bo)->assertOk();

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'suspended' WHERE id = ?")->execute([$bo]);

    $list = test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->assertOk()->json('data');
    expect($list[0]['members'][0])->toMatchArray(['membership_id' => $bo, 'status' => 'deactivated'])
        ->and(grpPairs($group))->toBe([$bo]);

    $row = test()->getJson('/api/v1/admin/members/'.$bo, GRP_HEADERS)->assertOk()->json('data');
    expect($row['status'])->toBe('deactivated')->and($row['groups'])->toBe([['id' => $group, 'name' => 'Sales']]);

    [, $cy] = grpMember($workspace, 'cy@example.test', status: 'suspended');
    grpAdd($group, $cy)->assertOk()->assertJsonPath('data.member_count', 2);
});

it('lists the groups with search, whitelisted sort, counts and members; an empty Workspace has none', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);

    $empty = test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($empty->json('data'))->toBe([])->and($empty->json('meta'))->toMatchArray(['total' => 0, 'matched' => 0, 'sort' => 'name', 'direction' => 'asc']);

    [, $bo] = grpMember($workspace, 'bo@example.test', name: 'Bo User');
    [, $cy] = grpMember($workspace, 'cy@example.test', name: 'Cy User');
    $alpha = grpCreate('Alpha')->json('data.group_id');
    $beta = grpCreate('beta')->json('data.group_id');
    grpCreate('100% Done')->assertCreated();
    grpAdd($beta, $bo)->assertOk();
    grpAdd($beta, $cy)->assertOk();
    grpAdd($alpha, $cy)->assertOk();

    $names = fn (string $query) => array_column(test()->getJson('/api/v1/admin/groups?'.$query, GRP_HEADERS)->assertOk()->json('data'), 'name');

    expect($names(''))->toBe(['100% Done', 'Alpha', 'beta'])
        ->and($names('direction=desc'))->toBe(['beta', 'Alpha', '100% Done'])
        ->and($names('sort=members&direction=desc'))->toBe(['beta', 'Alpha', '100% Done'])
        ->and($names('sort=members'))->toBe(['100% Done', 'Alpha', 'beta'])
        ->and($names('sort=password_hash'))->toBe(['100% Done', 'Alpha', 'beta'])
        ->and($names('q=ALPH'))->toBe(['Alpha'])
        ->and($names('q=%25'))->toBe(['100% Done'])
        ->and($names('q=_'))->toBe([])
        ->and($names('q=nothing'))->toBe([]);

    $filtered = test()->getJson('/api/v1/admin/groups?q=nothing', GRP_HEADERS)->json('meta');
    expect($filtered)->toMatchArray(['total' => 3, 'matched' => 0]);

    $rows = array_column(test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->json('data'), null, 'name');
    expect($rows['beta']['member_count'])->toBe(2)
        ->and(array_column($rows['beta']['members'], 'name'))->toBe(['Bo User', 'Cy User'])
        ->and(array_keys($rows['beta']))->toBe(['group_id', 'name', 'member_count', 'members', 'created_at', 'updated_at'])
        ->and(array_keys($rows['beta']['members'][0]))->toBe(['membership_id', 'name', 'email', 'status']);

    test()->getJson('/api/v1/admin/groups?q='.rawurlencode("a\0b"), GRP_HEADERS)->assertStatus(422);
});

it('never lists another Workspace\'s groups or their members', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    grpAdmin($acme);
    [, $zed] = grpMember($other, 'zed@example.test');
    $theirs = Cluster::seedGroup($other, 'Theirs');
    Cluster::superuser()->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $other, $theirs, $zed]);
    grpCreate('Mine')->assertCreated();

    $list = test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->assertOk()->json('data');
    expect(array_column($list, 'name'))->toBe(['Mine']);

    $members = test()->getJson('/api/v1/admin/members', GRP_HEADERS)->assertOk()->json('data');
    expect(json_encode($members))->not->toContain('Theirs')->and(json_encode($members))->not->toContain('zed@example.test');
});

it('denies an Admin without users.manage on every group route with access.not_authorized and audits it', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace, ['blocks.edit']);
    $group = Cluster::seedGroup($workspace, 'Sales');
    [, $bo] = grpMember($workspace, 'bo@example.test');
    $calls = [
        fn () => test()->getJson('/api/v1/admin/groups', GRP_HEADERS),
        fn () => grpCreate('New'),
        fn () => test()->patchJson("/api/v1/admin/groups/{$group}", ['name' => 'Renamed'], GRP_HEADERS),
        fn () => test()->deleteJson("/api/v1/admin/groups/{$group}", [], GRP_HEADERS),
        fn () => grpAdd($group, $bo),
        fn () => grpRemove($group, $bo),
    ];

    foreach ($calls as $n => $call) {
        Cache::flush();
        $before = (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
        $response = $call();
        $after = (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];

        $response->assertForbidden()->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);
        expect($after)->toBe($before + 1, "call {$n} audited");
    }

    expect(array_column(grpGroups(), 'name'))->toBe(['Sales'])->and(grpAudits())->toBe([])->and(grpPairs($group))->toBe([]);
});

it('denies the User area and a demoted Admin on the Groups page and the group API', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = grpAdmin($workspace);

    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->assertForbidden();
    test()->get(route('admin.users.groups'))->assertForbidden();

    test()->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    test()->get(route('admin.users.groups'))->assertOk();
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);
    test()->getJson('/api/v1/admin/groups', GRP_HEADERS)->assertForbidden();
});

it('renders the Groups page for an Admin with users.manage', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);

    test()->get(route('admin.users.groups'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/UserGroups'));
});

it('rolls everything back when the audit cannot be written (audit and outbox share the change\'s transaction)', function () {
    $workspace = Cluster::workspace('Acme');
    grpAdmin($workspace);
    // The outbox write fails at the database, so nothing the request wrote may remain.
    Cluster::superuser()->exec("ALTER TABLE outbox_events ADD CONSTRAINT grp_block CHECK (type <> 'access.group.changed')");

    try {
        test()->withoutExceptionHandling();
        expect(fn () => grpCreate('Sales'))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE outbox_events DROP CONSTRAINT grp_block');
    }

    expect(grpGroups())->toBe([])->and(grpAudits())->toBe([]);
});

it('keeps the tables forced under row-level security, the owner, grants and the unique name index', function () {
    Cluster::migrateOnce();

    foreach (['user_groups', 'group_members'] as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator']);

        $privileges = Cluster::rows(Cluster::superuser(), "select privilege_type from information_schema.role_table_grants where table_name = ? and grantee = 'app' order by privilege_type", [$table]);
        expect(array_column($privileges, 'privilege_type'))->toBe(['INSERT', 'SELECT', 'UPDATE']);
    }

    $functions = Cluster::rows(Cluster::superuser(), "select proname, pg_get_userbyid(proowner) as owner, prosecdef, has_function_privilege('app', oid, 'EXECUTE') as app_can, has_function_privilege('public', oid, 'EXECUTE') as public_can from pg_proc where proname in ('access_delete_group', 'access_remove_group_member') order by proname");
    expect($functions)->toBe([
        ['proname' => 'access_delete_group', 'owner' => 'migrator', 'prosecdef' => true, 'app_can' => true, 'public_can' => false],
        ['proname' => 'access_remove_group_member', 'owner' => 'migrator', 'prosecdef' => true, 'app_can' => true, 'public_can' => false],
    ]);

    $index = Cluster::rows(Cluster::superuser(), "select indexdef from pg_indexes where indexname = 'user_groups_workspace_name_unique'");
    expect($index[0]['indexdef'])->toContain('UNIQUE')->toContain('lower(btrim(')->toContain('workspace_id');
});

it('refuses at the database a duplicate name, a name that is not trimmed, and a pair across Workspaces', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $group = Cluster::seedGroup($a, 'Sales');
    [, $foreign] = grpMember($b, 'zed@example.test');
    $super = Cluster::superuser();

    expect(fn () => Cluster::seedGroup($a, 'SALES'))->toThrow(PDOException::class, 'user_groups_workspace_name_unique')
        ->and(fn () => Cluster::seedGroup($a, ' Padded '))->toThrow(PDOException::class, 'user_groups_name_check')
        ->and(fn () => Cluster::seedGroup($a, str_repeat('x', 65)))->toThrow(PDOException::class)
        ->and(fn () => $super->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')->execute([(string) Str::uuid7(), $a, $group, $foreign]))->toThrow(PDOException::class, 'group_members_membership_same_workspace')
        ->and(fn () => $super->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')->execute([(string) Str::uuid7(), $b, $group, $foreign]))->toThrow(PDOException::class, 'group_members_group_same_workspace');

    Cluster::seedGroup($b, 'Sales');
});

it('lets the removal functions act for app only inside the transaction Workspace, and refuse an unset context', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $mine] = grpMember($acme, 'ada@example.test');
    [, $foreign] = grpMember($other, 'zed@example.test');
    $group = Cluster::seedGroup($acme, 'Sales');
    $theirs = Cluster::seedGroup($other, 'Theirs');
    $insert = fn (string $workspace, string $g, string $m) => Cluster::superuser()->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')->execute([(string) Str::uuid7(), $workspace, $g, $m]);
    $insert($acme, $group, $mine);
    $insert($other, $theirs, $foreign);
    $app = Cluster::directApp();

    // Role app has no DELETE on the tables themselves.
    expect(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec('delete from group_members')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec('delete from user_groups')))->toThrow(PDOException::class, 'permission denied');

    // An unset context is refused.
    expect(fn () => $app->exec("select access_remove_group_member('{$group}', '{$mine}')"))->toThrow(PDOException::class)
        ->and(fn () => $app->exec("select access_delete_group('{$group}')"))->toThrow(PDOException::class);

    // Another Workspace's ids match nothing.
    $removed = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select access_remove_group_member(?::uuid, ?::uuid) as r', [$theirs, $foreign])[0]['r']);
    $deleted = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select access_delete_group(?::uuid) as r', [$theirs])[0]['r']);
    expect($removed)->toBeFalse()->and($deleted)->toBeNull()
        ->and(grpPairs($theirs))->toBe([$foreign])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from user_groups where id = ?', [$theirs]))->toHaveCount(1);

    // Its own: removed, then deleted (the number of memberships removed comes back).
    $removed = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select access_remove_group_member(?::uuid, ?::uuid) as r', [$group, $mine])[0]['r']);
    $again = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select access_remove_group_member(?::uuid, ?::uuid) as r', [$group, $mine])[0]['r']);
    $insert($acme, $group, $mine);
    $deleted = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select access_delete_group(?::uuid) as r', [$group])[0]['r']);

    expect($removed)->toBeTrue()->and($again)->toBeFalse()->and($deleted)->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from user_groups where id = ?', [$group]))->toBe([]);
});

it('refuses at the database a name with zero-width or format characters', function (string $name) {
    $a = Cluster::workspace('A');

    expect(fn () => Cluster::seedGroup($a, $name))->toThrow(PDOException::class, 'user_groups_name_check');
})->with([
    'zero-width space' => ["Sa\u{200B}les"],
    'bidi mark' => ["Sa\u{200E}les"],
    'line separator' => ["Sa\u{2028}les"],
    'word joiner' => ["Sa\u{2060}les"],
    'byte order mark' => ["Sa\u{FEFF}les"],
]);

it('refuses a name with such characters through the API with a 422', function () {
    grpAdmin(Cluster::workspace('Acme'));

    grpCreate("Sa\u{200B}les")->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
});

it('returns no groups for a membership of another Workspace from the member-groups query', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $zed] = grpMember($other, 'zed@example.test');
    $theirs = Cluster::seedGroup($other, 'Theirs');
    Cluster::superuser()->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $other, $theirs, $zed]);

    $directory = app(SqlMemberDirectory::class);
    $method = new ReflectionMethod($directory, 'withPermissions');
    $found = (object) ['id' => $zed, 'kind' => 'member', 'name' => 'Zed', 'email' => 'zed@example.test', 'role' => 'user', 'status' => 'active', 'last_active' => null, 'revision' => 1];

    // Inside Acme's transaction, with the foreign membership ID handed to the query directly.
    $rows = app(WorkspaceTransaction::class)->run($acme, fn () => $method->invoke($directory, $acme, [$found]));

    expect($rows[0]->groups)->toBe([]);
});

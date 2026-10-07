<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Access\Application\MembershipLocks;
use App\Modules\Access\Contracts\EditorNotAuthorized;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\LastUsersManageHolder;
use App\Modules\Access\Contracts\MemberActivation;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Identity\Contracts\SessionRevocation;
use App\Modules\Identity\Infrastructure\DatabaseSessionRevocation;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.24 against the real PostgreSQL: an Admin with `users.manage` deactivates and reactivates a member under the
// Story 1.22 rules (revision, self, last holder), the member's sessions for the Workspace are deleted, their next
// request fails closed, sign-in into the Workspace is refused while another Workspace still works, and role,
// permissions and groups come back on reactivation.
const STS_PASSWORD = 'status-password-1';

beforeEach(fn () => $this->withoutVite());

/** A member of the Workspace; returns [user ID, membership ID]. An existing email reuses its user. */
function stsMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], string $status = 'active'): array
{
    $existing = Cluster::rows(Cluster::superuser(), 'select id from users where email = ?', [$email]);
    $user = $existing === [] ? Cluster::user($email) : (int) $existing[0]['id'];
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(STS_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

/** @return array{0: int, 1: string} the Admin holding `users.manage`, signed in */
function stsAdmin(string $workspaceId, array $permissions = ['users.manage']): array
{
    [$user, $membership] = stsMember($workspaceId, 'ada@example.test', 'admin', $permissions);
    stsActAs($user, $workspaceId);

    return [$user, $membership];
}

function stsActAs(int $user, string $workspaceId, string $area = 'admin'): void
{
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => $area]);
}

function stsPost(string $membership, string $action, array $payload)
{
    return test()->postJson("/api/v1/admin/members/{$membership}/{$action}", $payload, ['Referer' => 'http://localhost:8000']);
}

function stsRow(string $membership): array
{
    return Cluster::rows(Cluster::superuser(), 'select role, revision, status from workspace_memberships where id = ?', [$membership])[0];
}

function stsAudits(string $action): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at', [$action]);
}

function stsEvents(string $type): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from outbox_events where type = ? order by occurred_at', [$type]);
}

/** A row of the `sessions` table; the payload is stored the way the session handler does (base64 of the encoded data). */
function stsSession(string $id, int $user, string|array $payload): void
{
    $stored = is_array($payload) ? base64_encode(serialize($payload)) : $payload;
    Cluster::superuser()->prepare('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)')->execute([$id, $user, $stored, time()]);
}

/** Revokes as the `database` session driver does, while the requests of the test keep their array session. */
function stsDatabaseSessions(): void
{
    app()->instance(SessionRevocation::class, new DatabaseSessionRevocation('database'));
}

function stsSessionIds(): array
{
    $ids = array_column(Cluster::rows(Cluster::superuser(), 'select id from sessions'), 'id');
    sort($ids);

    return $ids;
}

function stsNothingChanged(): void
{
    expect(stsAudits('access.membership.deactivated'))->toBe([])
        ->and(stsAudits('access.membership.reactivated'))->toBe([])
        ->and(stsEvents('access.membership.deactivated'))->toBe([])
        ->and(stsEvents('access.membership.reactivated'))->toBe([]);
}

it('deactivates a member: status, revision plus one, audit and outbox, and role, permissions and groups kept', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    $group = Cluster::seedGroup($workspace, 'Finance');
    Cluster::superuser()->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspace, $group, $bo]);

    $response = stsPost($bo, 'deactivate', ['revision' => 1])->assertOk();

    expect($response->json('data'))->toMatchArray(['kind' => 'member', 'membership_id' => $bo, 'status' => 'deactivated', 'revision' => 2])
        ->and(stsRow($bo))->toMatchArray(['role' => 'admin', 'revision' => 2, 'status' => 'deactivated'])
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ?', [$bo]), 'permission'))->toBe(['blocks.edit'])
        ->and(Cluster::rows(Cluster::superuser(), 'select 1 from group_members where membership_id = ?', [$bo]))->toHaveCount(1);

    $audit = stsAudits('access.membership.deactivated');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('membership:'.$bo)
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['membership_id' => $bo, 'status' => 'active'])
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['membership_id' => $bo, 'status' => 'deactivated'])
        ->and($audit[0]['after_state'])->not->toContain('@');

    $event = stsEvents('access.membership.deactivated');
    expect($event)->toHaveCount(1)
        ->and(json_decode($event[0]['data'], true))->toEqual(['membership_id' => $bo, 'status' => 'deactivated', 'previous_status' => 'active']);

    // The list shows the member as Deactivated with their groups.
    $listed = collect($this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertOk()->json('data'))->firstWhere('membership_id', $bo);
    expect($listed['status'])->toBe('deactivated')->and($listed['groups'])->toHaveCount(1);
});

it('reactivates a member with the previous role, permissions and groups, audited as access.membership.reactivated', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['blocks.edit', 'audit.view']);

    stsPost($bo, 'deactivate', ['revision' => 1])->assertOk();
    $response = stsPost($bo, 'reactivate', ['revision' => 2])->assertOk();

    expect($response->json('data'))->toMatchArray(['membership_id' => $bo, 'status' => 'active', 'revision' => 3])
        ->and(stsRow($bo))->toMatchArray(['role' => 'admin', 'revision' => 3, 'status' => 'active'])
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select permission from membership_permissions where membership_id = ? order by permission', [$bo]), 'permission'))->toBe(['audit.view', 'blocks.edit']);

    $audit = stsAudits('access.membership.reactivated');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['status' => 'active'])
        ->and(stsEvents('access.membership.reactivated'))->toHaveCount(1);
});

it('deletes the member\'s sessions for this Workspace only, leaving other Workspaces, other people and undecodable payloads alone', function () {
    stsDatabaseSessions();
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    stsAdmin($acme);
    [$bo, $boAcme] = stsMember($acme, 'bo@example.test');
    stsMember($other, 'bo@example.test');
    [$cy] = stsMember($acme, 'cy@example.test');

    stsSession('acme-php', $bo, ['workspace_id' => $acme, 'area' => 'user']);
    stsSession('acme-php-upper', $bo, ['workspace_id' => strtoupper($acme), 'area' => 'user']);
    stsSession('acme-json', $bo, base64_encode(json_encode(['workspace_id' => $acme, 'area' => 'user'])));
    stsSession('other-ws', $bo, ['workspace_id' => $other, 'area' => 'user']);
    stsSession('no-ws', $bo, ['area' => 'user']);
    stsSession('garbage', $bo, 'not-a-session-payload');
    // An object payload is never instantiated: it does not decode, so the row is left alone.
    stsSession('object', $bo, base64_encode('O:8:"stdClass":1:{s:12:"workspace_id";s:36:"'.$acme.'";}'));
    stsSession('cy-acme', $cy, ['workspace_id' => $acme, 'area' => 'user']);

    stsPost($boAcme, 'deactivate', ['revision' => 1])->assertOk();

    expect(stsSessionIds())->toBe(['cy-acme', 'garbage', 'no-ws', 'object', 'other-ws']);
});

it('fails the deactivated member\'s next request: 401 for JSON and Inertia, a redirect to sign-in for a page, keys dropped', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [$bo, $boMembership] = stsMember($workspace, 'bo@example.test', 'admin', ['users.manage']);

    stsPost($boMembership, 'deactivate', ['revision' => 1])->assertOk();

    // The member's session (still open: it was written after, or could not be decoded).
    stsActAs($bo, $workspace);
    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertUnauthorized()
        ->assertJsonPath('error.code', 'platform.unauthenticated');
    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();
    $this->assertGuest('web');

    stsActAs($bo, $workspace);
    $this->get(route('admin.users.index'), [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ])->assertUnauthorized();

    stsActAs($bo, $workspace, 'user');
    $this->get(route('overview'))->assertRedirect(route('login'));
    expect(session('workspace_id'))->toBeNull();
    $this->assertGuest('web');
});

it('keeps an active member\'s request working and reads the membership again on every request', function () {
    $workspace = Cluster::workspace('Acme');
    [$ada] = stsAdmin($workspace);
    stsMember($workspace, 'bo@example.test');

    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertOk();

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'deactivated' WHERE user_id = ?")->execute([$ada]);
    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertUnauthorized();
});

it('refuses sign-in into a Workspace where the membership is deactivated with the signin-failed wording, and does not offer it in the switcher', function () {
    $acme = Cluster::workspace('Acme');
    $beta = Cluster::workspace('Beta');
    stsAdmin($acme);
    [, $boAcme] = stsMember($acme, 'bo@example.test');

    stsPost($boAcme, 'deactivate', ['revision' => 1])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    // Only a deactivated membership: neutral failure, no session, same wording as a wrong password.
    foreach (['user', 'admin'] as $role) {
        $this->postJson('/login', ['email' => 'bo@example.test', 'password' => STS_PASSWORD, 'role' => $role])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);
    }
    $this->assertGuest();

    // A person who also belongs to another Workspace still signs in to that one; the deactivated one is not offered.
    stsMember($beta, 'bo@example.test', 'user');
    $this->postJson('/login', ['email' => 'bo@example.test', 'password' => STS_PASSWORD, 'role' => 'user'])->assertOk();
    expect(session('workspace_id'))->toBe($beta);

    $props = null;
    $this->get(route('overview'))->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];

        return $page;
    });
    expect(array_column($props['shell']['workspaces'], 'id'))->toBe([$beta]);
});

it('refuses deactivating one\'s own membership with 403 access.self_change_forbidden, audited as a refused attempt', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = stsAdmin($workspace);

    stsPost($editor, 'deactivate', ['revision' => 1])->assertForbidden()->assertJsonPath('error.code', ErrorCode::SelfChangeForbidden->value);

    expect(stsRow($editor))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();

    $denied = stsAudits('access.admin.denied');
    expect($denied)->toHaveCount(1)
        ->and(json_decode($denied[0]['after_state'], true))->toMatchArray(['route' => 'api.admin.members.deactivate', 'reason' => 'self_change']);
});

it('refuses deactivating the last active users.manage holder with 409 access.last_users_manage_holder (the shared rule, under the lock)', function () {
    $workspace = Cluster::workspace('Acme');
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['users.manage']);
    // Not active Admin holders: a User with a stale row, and a suspended Admin with the permission.
    stsMember($workspace, 'stale@example.test', 'user', ['users.manage']);
    stsMember($workspace, 'gone@example.test', 'admin', ['users.manage'], 'suspended');
    $locks = app(MembershipLocks::class);
    $others = fn () => app(WorkspaceTransaction::class)->run($workspace, fn () => $locks->otherHolders($workspace, $bo));

    // The shared predicate: only an active Admin holding users.manage counts.
    expect($locks->isActiveHolder('admin', 'active', ['users.manage']))->toBeTrue()
        ->and($locks->isActiveHolder('user', 'active', ['users.manage']))->toBeFalse()
        ->and($locks->isActiveHolder('admin', 'suspended', ['users.manage']))->toBeFalse()
        ->and($locks->isActiveHolder('admin', 'active', ['blocks.edit']))->toBeFalse()
        // Nobody else holds it (the stale User and the suspended Admin do not count): Bo is the last holder.
        ->and($others())->toBe([]);

    // With another active Admin holder Bo is no longer the last one.
    stsMember($workspace, 'cy@example.test', 'admin', ['users.manage']);
    expect($others())->toHaveCount(1)
        ->and(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
});

it('maps the refusal to 409 access.last_users_manage_holder over HTTP', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['users.manage']);

    $this->mock(MemberActivation::class)->shouldReceive('deactivate')->andThrow(new LastUsersManageHolder);

    stsPost($bo, 'deactivate', ['revision' => 1])->assertStatus(409)->assertJsonPath('error.code', ErrorCode::LastUsersManageHolder->value);

    $denied = stsAudits('access.admin.denied');
    expect($denied)->toHaveCount(1)
        ->and(json_decode($denied[0]['after_state'], true))->toMatchArray(['route' => 'api.admin.members.deactivate', 'reason' => 'last_users_manage_holder'])
        ->and($denied[0]['actor'])->toBe($editor)
        ->and(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
});

it('answers 409 with the current state for a stale revision and changes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);

    $response = stsPost($bo, 'deactivate', ['revision' => 7])->assertStatus(409)->assertJsonPath('error.code', ErrorCode::RevisionConflict->value);

    expect($response->json('current'))->toMatchArray(['role' => 'admin', 'permissions' => ['blocks.edit'], 'revision' => 1, 'status' => 'active'])
        ->and(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();

    // The same for a reactivation of a deactivated member.
    stsPost($bo, 'deactivate', ['revision' => 1])->assertOk();
    stsPost($bo, 'reactivate', ['revision' => 1])->assertStatus(409)->assertJsonPath('current.status', 'deactivated');
    expect(stsRow($bo))->toMatchArray(['status' => 'deactivated', 'revision' => 2]);
});

it('treats a repeat as a 200 no-op without audit, outbox or revision change', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test');

    // Already active: reactivating is a no-op.
    stsPost($bo, 'reactivate', ['revision' => 1])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.revision', 1);
    stsNothingChanged();

    stsPost($bo, 'deactivate', ['revision' => 1])->assertOk();
    // Already deactivated: deactivating again is a no-op, whatever revision the client holds.
    stsPost($bo, 'deactivate', ['revision' => 1])->assertOk()->assertJsonPath('data.status', 'deactivated')->assertJsonPath('data.revision', 2);

    expect(stsAudits('access.membership.deactivated'))->toHaveCount(1)
        ->and(stsEvents('access.membership.deactivated'))->toHaveCount(1)
        ->and(stsRow($bo))->toMatchArray(['status' => 'deactivated', 'revision' => 2]);
});

it('answers 404 for a membership of another Workspace or one that does not exist, and changes nothing there', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    stsAdmin($acme);
    [, $foreign] = stsMember($other, 'zed@example.test');

    stsPost($foreign, 'deactivate', ['revision' => 1])->assertNotFound();
    stsPost((string) Str::uuid7(), 'deactivate', ['revision' => 1])->assertNotFound();
    stsPost('not-a-uuid', 'reactivate', ['revision' => 1])->assertNotFound();

    expect(stsRow($foreign))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();
});

it('requires users.manage', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace, ['blocks.edit']);
    [, $bo] = stsMember($workspace, 'bo@example.test');

    stsPost($bo, 'deactivate', ['revision' => 1])->assertForbidden()->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);
    stsPost($bo, 'reactivate', ['revision' => 1])->assertForbidden();

    expect(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();
});

it('requires the revision and changes nothing without it', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test');

    stsPost($bo, 'deactivate', [])->assertStatus(422)->assertJsonStructure(['errors' => ['revision']]);
    stsPost($bo, 'deactivate', ['revision' => 'x'])->assertStatus(422);

    expect(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();
});

it('does not delete sessions when the change is refused', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [$bo, $boMembership] = stsMember($workspace, 'bo@example.test');
    stsDatabaseSessions();
    stsSession('bo-acme', $bo, ['workspace_id' => $workspace, 'area' => 'user']);

    stsPost($boMembership, 'deactivate', ['revision' => 9])->assertStatus(409);

    expect(stsSessionIds())->toBe(['bo-acme']);
});

it('signs out a member whose membership is suspended or removed, or who has none in the session\'s Workspace', function (string $case) {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [$bo] = stsMember($acme, 'bo@example.test', 'user', [], $case === 'none' ? 'active' : $case);
    [$zed] = stsMember($other, 'zed@example.test');

    stsActAs($case === 'none' ? $zed : $bo, $acme, 'user');
    $this->getJson('/api/v1/admin/members', ['Referer' => 'http://localhost:8000'])->assertUnauthorized();
    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();

    stsActAs($case === 'none' ? $zed : $bo, $acme, 'user');
    $this->get(route('overview'))->assertRedirect(route('login'));
})->with(['suspended', 'removed', 'none']);

it('encrypts, skips and counts: revokes encrypted payloads, skips other drivers with a notice and logs undecodable rows', function () {
    $acme = Cluster::workspace('Acme');
    [$bo] = stsMember($acme, 'bo@example.test');
    $revocation = app(SessionRevocation::class);

    // session.encrypt: the stored payload is base64 of the encrypted serialized data.
    config(['session.driver' => 'database', 'session.encrypt' => true]);
    stsSession('enc', $bo, base64_encode(encrypt(serialize(['workspace_id' => $acme]))));
    stsSession('enc-json', $bo, base64_encode(encrypt(json_encode(['workspace_id' => $acme]))));
    stsSession('plain-while-encrypted', $bo, ['workspace_id' => $acme]);
    Log::spy();
    expect($revocation->revokeForWorkspace($bo, $acme))->toBe(2)
        ->and(stsSessionIds())->toBe(['plain-while-encrypted']);
    Log::shouldHaveReceived('notice')->with('identity.session.revocation_undecodable', ['reason' => 'payload_not_decodable', 'count' => 1])->once();

    // Another driver: nothing is touched and a notice says why.
    config(['session.driver' => 'redis', 'session.encrypt' => false]);
    stsSession('kept', $bo, ['workspace_id' => $acme]);
    Log::spy();
    expect($revocation->revokeForWorkspace($bo, $acme))->toBe(0)
        ->and(stsSessionIds())->toBe(['kept', 'plain-while-encrypted']);
    Log::shouldHaveReceived('notice')->with('identity.session.revocation_skipped', ['reason' => 'driver_not_database'])->once();
});

it('records the number of revoked sessions in the audit and revokes again on a repeated deactivation', function () {
    stsDatabaseSessions();
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [$bo, $boMembership] = stsMember($workspace, 'bo@example.test');
    stsSession('one', $bo, ['workspace_id' => $workspace]);
    stsSession('two', $bo, ['workspace_id' => $workspace]);

    stsPost($boMembership, 'deactivate', ['revision' => 1])->assertOk();

    $audit = stsAudits('access.membership.deactivated');
    expect(json_decode($audit[0]['after_state'], true))->toMatchArray(['session_count' => 2])
        ->and(stsSessionIds())->toBe([]);

    // A session that outlived the first deactivation is ended by the repeat, which is still a no-op for the rest.
    stsSession('late', $bo, ['workspace_id' => $workspace]);
    stsPost($boMembership, 'deactivate', ['revision' => 2])->assertOk()->assertJsonPath('data.revision', 2);

    expect(stsSessionIds())->toBe([])
        ->and(stsAudits('access.membership.deactivated'))->toHaveCount(1)
        ->and(stsEvents('access.membership.deactivated'))->toHaveCount(1);
});

it('refuses an editor who lost users.manage or the Admin role before the lock, changing nothing', function (string $loss) {
    $workspace = Cluster::workspace('Acme');
    [$ada, $editor] = stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test');
    [, $cy] = stsMember($workspace, 'cy@example.test', 'admin', ['users.manage']);
    $stale = new MemberEditor($ada, $editor, $workspace);

    // The editor passed the route gate, then lost the authority before the service locked the rows.
    Cluster::superuser()->exec($loss === 'permission'
        ? "DELETE FROM membership_permissions WHERE membership_id = '{$editor}'"
        : "UPDATE workspace_memberships SET role = 'user' WHERE id = '{$editor}'");

    $service = app(MemberActivation::class);
    expect(fn () => $service->deactivate($stale, $bo, 1))->toThrow(EditorNotAuthorized::class)
        ->and(fn () => $service->reactivate($stale, $cy, 1))->toThrow(EditorNotAuthorized::class)
        ->and(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
    stsNothingChanged();
})->with(['permission', 'role']);

it('answers 403 and records access.admin.denied with reason not_authorized when the editor lost authority meanwhile', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test');

    $this->mock(MemberActivation::class)->shouldReceive('deactivate')->andThrow(new EditorNotAuthorized);

    stsPost($bo, 'deactivate', ['revision' => 1])->assertForbidden()->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);

    $denied = stsAudits('access.admin.denied');
    expect($denied)->toHaveCount(1)
        ->and(json_decode($denied[0]['after_state'], true))->toMatchArray(['route' => 'api.admin.members.deactivate', 'reason' => 'not_authorized'])
        ->and($denied[0]['actor'])->toBe($editor)
        ->and(stsRow($bo))->toMatchArray(['status' => 'active', 'revision' => 1]);
});

it('throttles the status routes with 429', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test');
    RateLimiter::clear('x');

    foreach (range(1, 30) as $ignored) {
        stsPost($bo, 'reactivate', ['revision' => 1])->assertOk();
    }

    stsPost($bo, 'reactivate', ['revision' => 1])->assertStatus(429);
    stsPost($bo, 'deactivate', ['revision' => 1])->assertStatus(429);
});

it('lets a reactivated member sign in again end to end, with the previous role and permissions', function () {
    $workspace = Cluster::workspace('Acme');
    stsAdmin($workspace);
    [, $bo] = stsMember($workspace, 'bo@example.test', 'admin', ['blocks.edit']);
    $login = fn () => $this->postJson('/login', ['email' => 'bo@example.test', 'password' => STS_PASSWORD, 'role' => 'admin']);

    stsPost($bo, 'deactivate', ['revision' => 1])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $login()->assertStatus(422)->assertJsonValidationErrors(['email' => 'signin-failed']);

    stsActAs(Cluster::rows(Cluster::superuser(), 'select id from users where email = ?', ['ada@example.test'])[0]['id'], $workspace);
    stsPost($bo, 'reactivate', ['revision' => 2])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $login()->assertOk();
    expect(session('workspace_id'))->toBe($workspace)->and(session('area'))->toBe('admin')
        ->and(stsRow($bo))->toMatchArray(['role' => 'admin', 'status' => 'active']);
});

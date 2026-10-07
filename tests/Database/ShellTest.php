<?php

use App\Models\User;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Identity\Contracts\SignInMemberships;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// The Story 1.16 shell against the real PostgreSQL: the navigation model reads membership_permissions under
// row-level security through the Access module, and sign-out rotates, destroys and audits on its own connection.
beforeEach(fn () => $this->withoutVite());

const SHELL_PASSWORD = 'a-long-enough-password';

function shellMember(string $workspaceId, string $email, string $role, array $permissions = [], string $status = 'active'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(SHELL_PASSWORD), $user]);

    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        shellGrant($workspaceId, $membership, $permission);
    }

    return [$user, $membership];
}

function shellGrant(string $workspaceId, string $membership, string $permission): void
{
    Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
}

function shellSignIn(string $email, string $role): void
{
    test()->postJson('/login', ['email' => $email, 'password' => SHELL_PASSWORD, 'role' => $role])->assertOk();
}

/** The `shell` prop of a page, as the client receives it. */
function shellProps(string $url): array
{
    $props = null;

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props']['shell'];

        return $page;
    });

    return $props;
}

/** @return array<string, array{href: string, permission: string|null, allowed: bool}> */
function shellItems(array $shell): array
{
    $items = [];
    foreach ($shell['items'] as $item) {
        $items[$item['key']] = ['href' => $item['href'], 'permission' => $item['permission'], 'allowed' => $item['allowed']];
    }

    return $items;
}

it('shares the User navigation, the Workspace and the role with a User', function () {
    $workspace = Cluster::workspace('Acme Industries');
    shellMember($workspace, 'ada@example.test', 'user');

    shellSignIn('ada@example.test', 'user');
    $shell = shellProps(route('overview'));

    expect($shell['area'])->toBe('user')
        ->and($shell['role'])->toBe('user')
        ->and($shell['workspace'])->toBe(['id' => $workspace, 'name' => 'Acme Industries', 'label' => null])
        ->and(array_column($shell['items'], 'key'))->toBe(['overview', 'my-dashboards', 'templates', 'profile', 'help'])
        ->and(array_column($shell['items'], 'href'))->toBe(['/dashboard', '/dashboards', '/templates', '/settings/profile', '/help'])
        ->and(array_unique(array_column($shell['items'], 'allowed')))->toBe([true])
        ->and($shell['sign_out_href'])->toBe('/logout');
});

it('maps each Admin item to its permission, read from membership_permissions, and disables the rest', function () {
    $workspace = Cluster::workspace('Acme');
    shellMember($workspace, 'root@example.test', 'admin', ['blocks.edit', 'audit.view']);

    shellSignIn('root@example.test', 'admin');
    $items = shellItems(shellProps(route('admin.overview')));

    expect(array_keys($items))->toBe([
        'admin-overview', 'block-management', 'create-block', 'draft-blocks', 'published-blocks', 'block-categories',
        'dashboard-templates', 'data-sources', 'user-configuration', 'system-settings', 'audit-log',
    ])
        ->and(array_map(fn (array $i): ?string => $i['permission'], $items))->toBe([
            'admin-overview' => null,
            'block-management' => 'blocks.edit',
            'create-block' => 'blocks.edit',
            'draft-blocks' => 'blocks.edit',
            'published-blocks' => 'blocks.edit',
            'block-categories' => 'blocks.edit',
            'dashboard-templates' => 'templates.manage',
            'data-sources' => 'data_sources.manage',
            'user-configuration' => 'users.manage',
            'system-settings' => 'settings.manage',
            'audit-log' => 'audit.view',
        ])
        ->and(array_keys(array_filter($items, fn (array $i): bool => $i['allowed'])))->toBe([
            'admin-overview', 'block-management', 'create-block', 'draft-blocks', 'published-blocks', 'block-categories', 'audit-log',
        ]);
});

it('shows every item to an Admin who holds every permission', function () {
    $workspace = Cluster::workspace('Acme');
    shellMember($workspace, 'root@example.test', 'admin', ['blocks.edit', 'templates.manage', 'data_sources.manage', 'users.manage', 'settings.manage', 'audit.view']);

    shellSignIn('root@example.test', 'admin');

    expect(array_unique(array_column(shellItems(shellProps(route('admin.overview'))), 'allowed')))->toBe([true]);
});

it('applies a permission change on the next request and never reads another person or Workspace', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $membership] = shellMember($acme, 'root@example.test', 'admin');
    // Held by someone else, and by the same email in another Workspace: neither counts.
    shellMember($acme, 'peer@example.test', 'admin', ['users.manage']);
    shellMember($other, 'elsewhere@example.test', 'admin', ['users.manage']);

    shellSignIn('root@example.test', 'admin');
    expect(shellItems(shellProps(route('admin.overview')))['user-configuration']['allowed'])->toBeFalse();

    shellGrant($acme, $membership, 'users.manage');
    expect(shellItems(shellProps(route('admin.overview')))['user-configuration']['allowed'])->toBeTrue();

    Cluster::superuser()->prepare('DELETE FROM membership_permissions WHERE membership_id = ?')->execute([$membership]);
    expect(shellItems(shellProps(route('admin.overview')))['user-configuration']['allowed'])->toBeFalse();
});

it('shows the User navigation to an Admin session whose membership was demoted to User', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = shellMember($workspace, 'root@example.test', 'admin', ['users.manage']);

    shellSignIn('root@example.test', 'admin');
    expect(shellProps(route('admin.overview'))['area'])->toBe('admin');

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);

    $shell = shellProps(route('overview'));
    expect($shell['area'])->toBe('user')->and($shell['role'])->toBe('user')
        ->and(array_column($shell['items'], 'key'))->toBe(['overview', 'my-dashboards', 'templates', 'profile', 'help']);
    $this->get(route('admin.overview'))->assertForbidden();
});

it('ends an Admin session whose membership was deactivated with a redirect to sign-in', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = shellMember($workspace, 'root@example.test', 'admin', ['users.manage']);

    shellSignIn('root@example.test', 'admin');
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'suspended' WHERE id = ?")->execute([$membership]);

    $this->get(route('help'))->assertRedirect(route('login'));
    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();
});

it('shows no items when the session names no Workspace', function () {
    $user = User::query()->findOrFail(Cluster::user('nomad@example.test'));

    $this->actingAs($user)->get(route('help'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('shell.items', [])->where('shell.workspace', null));
});

it('reads no permissions of a deactivated membership', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = shellMember($workspace, 'root@example.test', 'admin', ['users.manage', 'audit.view']);
    $reader = app(MembershipPermissions::class);

    expect(array_map(fn ($p) => $p->value, $reader->forUser($user, $workspace)))->toEqualCanonicalizing(['users.manage', 'audit.view']);

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'suspended' WHERE id = ?")->execute([$membership]);

    expect($reader->forUser($user, $workspace))->toBe([]);
});

it('gates an Admin page request on the permission: a denied item answers 403 (Story 1.19)', function () {
    $workspace = Cluster::workspace('Acme');
    shellMember($workspace, 'root@example.test', 'admin');

    shellSignIn('root@example.test', 'admin');
    $this->get(route('admin.users.index'))->assertForbidden()->assertInertia(fn ($page) => $page->component('Forbidden'));
    $this->get(route('admin.overview'))->assertOk()->assertInertia(fn ($page) => $page->component('Placeholder')->where('page', 'admin-overview'));
});

it('signs out: rotates the session ID, ends the session, lands on sign-in and audits identity.signout.completed', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = shellMember($workspace, 'ada@example.test', 'admin');

    $this->startSession();
    shellSignIn('ada@example.test', 'admin');
    $before = session()->getId();
    $token = session()->token();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($before)
        ->and(session()->token())->not->toBe($token)
        ->and(session('workspace_id'))->toBeNull()
        ->and(session('area'))->toBeNull();

    $events = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'identity.signout.completed'");
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and($events[0]['security'])->toBeTrue()
        ->and($events[0]['actor'])->toBe($membership)
        ->and($events[0]['subject'])->toBe('membership:'.$membership)
        ->and(json_decode($events[0]['after_state'], true))->toEqualCanonicalizing(['user_id' => $user, 'membership_id' => $membership, 'area' => 'admin']);

    // The next request is a guest's.
    $this->get(route('overview'))->assertRedirect(route('login'));
});

it('signs a User out from the User area', function () {
    $workspace = Cluster::workspace('Acme');
    shellMember($workspace, 'ada@example.test', 'user');

    shellSignIn('ada@example.test', 'user');
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    $events = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'identity.signout.completed'");
    expect($events)->toHaveCount(1)
        ->and(json_decode($events[0]['after_state'], true)['area'])->toBe('user');
});

it('writes a log line, no audit row and still signs out when the session names no Workspace', function () {
    $user = Cluster::user('nomad@example.test');

    Log::spy();
    $this->actingAs(User::query()->findOrFail($user))->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    Log::shouldHaveReceived('warning')->with('identity.signout.completed', ['reason' => 'no_workspace'])->once();
    expect(Cluster::rows(Cluster::superuser(), "select id from audit_events where action = 'identity.signout.completed'"))->toBe([]);
});

it('signs out even when recording the event fails, and logs the failure', function () {
    $workspace = Cluster::workspace('Acme');
    shellMember($workspace, 'ada@example.test', 'user');
    shellSignIn('ada@example.test', 'user');

    // The membership lookup the audit needs now fails.
    app()->bind(SignInMemberships::class, fn () => new class implements SignInMemberships
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

    Log::spy();
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    Log::shouldHaveReceived('error')->with('identity.signout.record_failed', ['workspace_id' => $workspace, 'exception' => RuntimeException::class])->once();
    expect(Cluster::rows(Cluster::superuser(), "select id from audit_events where action = 'identity.signout.completed'"))->toBe([]);
});

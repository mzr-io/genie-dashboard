<?php

use App\Http\Navigation\ShellNavigation;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Access\Contracts\UserMembership;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

// The Story 1.16 shell on the SQLite Feature suite: routes, the navigation model and the sign-out answer.
// The real permission read and the audited sign-out run in the Database suite.
beforeEach(fn () => $this->withoutVite());

const SHELL_WORKSPACE = '0197f1a0-0000-7000-8000-000000000001';

/**
 * @param  list<Permission>  $held
 */
function shellFor(User $user, string $area, array $held = [], string $role = 'admin', string $status = 'active'): array
{
    app()->instance(MembershipLookup::class, new class($status, $role) implements MembershipLookup
    {
        public function __construct(private string $status, private string $role) {}

        public function forUser(int $userId): array
        {
            return [new UserMembership('m-1', SHELL_WORKSPACE, 'Acme Industries', null, 'active', $this->role, $this->status, null)];
        }
    });
    app()->instance(MembershipPermissions::class, new class($held) implements MembershipPermissions
    {
        /** @param list<Permission> $held */
        public function __construct(private array $held) {}

        public function forUser(int $userId, string $workspaceId): array
        {
            return $workspaceId === SHELL_WORKSPACE ? $this->held : [];
        }
    });

    $request = Request::create('/admin');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('area', $area);
    $request->session()->put('workspace_id', SHELL_WORKSPACE);

    return app(ShellNavigation::class)->for($request);
}

it('registers a named placeholder route for every navigation target, all behind auth', function () {
    $names = [
        ...array_values(ShellNavigation::USER_ITEMS),
        ...array_map(fn (array $item): string => $item[0], array_values(ShellNavigation::ADMIN_ITEMS)),
    ];

    expect($names)->toHaveCount(16);

    foreach ($names as $name) {
        $route = Router::getRoutes()->getByName($name);

        expect($route)->toBeInstanceOf(Route::class, "route {$name} is not registered");

        // `help` is open to guests (the sign-in page links to it); every other target needs a session.
        if ($name !== 'help') {
            expect($route->gatherMiddleware())->toContain('auth');
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }
});

it('renders the placeholder page of every navigation target for a signed-in person', function () {
    $this->actingAs(User::factory()->create());

    $pages = ['overview' => 'overview', 'dashboards.index' => 'my-dashboards', 'templates.index' => 'templates'];

    foreach ($pages as $name => $page) {
        $this->get(route($name))->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('Placeholder')
            ->where('page', $page)
            ->where('shell.area', 'user'));
    }

    // Admin pages are gated (Story 1.19): with no Workspace and no Admin area they are the 403 page.
    foreach (array_column(array_values(ShellNavigation::ADMIN_ITEMS), 0) as $name) {
        $this->get(route($name))->assertForbidden()->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('Forbidden'));
    }

    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('settings/Profile'));
    $this->get(route('help'))->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('Help')->where('shell.area', 'user'));
});

it('keeps Help & support open to guests', function () {
    $this->get(route('help'))->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('auth/Help')
        ->where('shell', null));
});

it('shares no navigation with a guest', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('shell', null));
});

it('shares no items when the session names no Workspace, keeping Help & support and Sign out', function () {
    $this->actingAs(User::factory()->create())->get(route('overview'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('shell.area', 'user')
            ->where('shell.workspace', null)
            ->where('shell.role', null)
            ->where('shell.items', [])
            ->where('shell.help_href', '/help')
            ->where('shell.sign_out_href', '/logout')
            ->where('shell.profile_href', '/settings/profile'));
});

it('shares the User navigation with a User member', function () {
    $shell = shellFor(User::factory()->create(), 'user', role: 'user');

    expect($shell['area'])->toBe('user')->and($shell['role'])->toBe('user')
        ->and(array_column($shell['items'], 'key'))->toBe(array_keys(ShellNavigation::USER_ITEMS))
        ->and(array_column($shell['items'], 'href'))->toBe(['/dashboard', '/dashboards', '/templates', '/settings/profile', '/help']);
});

it('shows the User navigation to an Admin member working in the User area', function () {
    $shell = shellFor(User::factory()->create(), 'user', role: 'admin');

    expect($shell['area'])->toBe('user')->and(array_column($shell['items'], 'key'))->toBe(array_keys(ShellNavigation::USER_ITEMS));
});

it('shows the User navigation when an admin-area session was demoted to User', function () {
    $shell = shellFor(User::factory()->create(), 'admin', [Permission::UsersManage], role: 'user');

    expect($shell['area'])->toBe('user')->and($shell['role'])->toBe('user')
        ->and(array_column($shell['items'], 'key'))->toBe(array_keys(ShellNavigation::USER_ITEMS));
});

it('shows no items to an admin-area session whose membership was deactivated', function () {
    $shell = shellFor(User::factory()->create(), 'admin', [Permission::UsersManage], status: 'deactivated');

    expect($shell['items'])->toBe([])->and($shell['workspace'])->toBeNull()->and($shell['role'])->toBeNull();
});

it('answers with no Workspace, role or items when the membership lookup throws, and logs it', function () {
    $user = User::factory()->create();
    app()->instance(MembershipLookup::class, new class implements MembershipLookup
    {
        public function forUser(int $userId): array
        {
            throw new RuntimeException('down');
        }
    });

    $request = Request::create('/help');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('area', 'admin');
    $request->session()->put('workspace_id', SHELL_WORKSPACE);

    Log::spy();
    $shell = app(ShellNavigation::class)->for($request);

    expect($shell['workspace'])->toBeNull()->and($shell['role'])->toBeNull()->and($shell['items'])->toBe([]);
    Log::shouldHaveReceived('error')->with('shell.membership.failed')->once();
});

it('builds the Admin navigation from the one permission constant', function () {
    $user = User::factory()->create();
    $shell = shellFor($user, 'admin', [Permission::BlocksEdit, Permission::AuditView]);

    expect($shell['area'])->toBe('admin')
        ->and($shell['workspace'])->toBe(['id' => SHELL_WORKSPACE, 'name' => 'Acme Industries', 'label' => null])
        ->and($shell['role'])->toBe('admin')
        ->and(array_column($shell['items'], 'key'))->toBe(array_keys(ShellNavigation::ADMIN_ITEMS));

    $allowed = [];
    foreach ($shell['items'] as $item) {
        $allowed[$item['key']] = $item['allowed'];
        expect($item['permission'])->toBe((ShellNavigation::ADMIN_ITEMS[$item['key']][1])?->value);
    }

    expect($allowed)->toBe([
        'admin-overview' => true,
        'block-management' => true,
        'create-block' => true,
        'draft-blocks' => true,
        'published-blocks' => true,
        'block-categories' => true,
        'dashboard-templates' => false,
        'data-sources' => false,
        'user-configuration' => false,
        'system-settings' => false,
        'audit-log' => true,
    ]);
});

it('pins the permission mapping', function () {
    expect(array_map(fn (array $item): ?string => $item[1]?->value, ShellNavigation::ADMIN_ITEMS))->toBe([
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
    ]);
});

it('lists every Admin item as disabled, none hidden, when the Admin holds nothing', function () {
    $shell = shellFor(User::factory()->create(), 'admin');

    expect($shell['items'])->toHaveCount(11)
        ->and(array_column(array_filter($shell['items'], fn (array $i): bool => $i['allowed']), 'key'))->toBe(['admin-overview']);
});

it('fails closed when the permission lookup breaks', function () {
    $user = User::factory()->create();
    shellFor($user, 'admin', [Permission::UsersManage]);
    app()->instance(MembershipPermissions::class, new class implements MembershipPermissions
    {
        public function forUser(int $userId, string $workspaceId): array
        {
            throw new RuntimeException('down');
        }
    });

    $request = Request::create('/admin');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('area', 'admin');
    $request->session()->put('workspace_id', SHELL_WORKSPACE);

    Log::spy();
    $shell = app(ShellNavigation::class)->for($request);

    expect(array_column(array_filter($shell['items'], fn (array $i): bool => $i['allowed']), 'key'))->toBe(['admin-overview']);
    Log::shouldHaveReceived('error')->with('shell.permissions.failed')->once();
});

it('ignores a malformed Workspace ID in the session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['area' => 'user', 'workspace_id' => 'not-a-uuid'])
        ->get(route('overview'))->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('shell.workspace', null));
});

it('signs out: rotates the session ID, destroys the session, lands on sign-in and clears the CSRF token', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['area' => 'user']);
    $this->startSession();
    $before = session()->getId();
    $token = session()->token();

    Log::spy();
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($before)
        ->and(session()->token())->not->toBe($token)
        ->and(session('area'))->toBeNull();
    Log::shouldHaveReceived('warning')->with('identity.signout.completed', ['reason' => 'no_workspace'])->once();
});

it('answers a JSON sign-out with 204', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('logout'), [], ['Referer' => 'http://localhost'])->assertNoContent();
    $this->assertGuest();
});

it('does not render an Appearance control or a theme setting', function () {
    $this->actingAs(User::factory()->create())->get(route('overview'))->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->missing('shell.appearance'));

    expect(Str::lower(json_encode(ShellNavigation::USER_ITEMS + ShellNavigation::ADMIN_ITEMS)))->not->toContain('appearance');
});

it('lists only usable memberships as switchable Workspaces, with label and role, and keeps the label verbatim', function () {
    $user = new User(['name' => 'Ada', 'email' => 'ada@example.test']);
    $user->id = 7;

    app()->instance(MembershipLookup::class, new class implements MembershipLookup
    {
        public function forUser(int $userId): array
        {
            return [
                new UserMembership('m-1', SHELL_WORKSPACE, 'Acme Industries', 'Acme <b>Production</b>', 'active', 'admin', 'active', null),
                new UserMembership('m-2', '0197f1a0-0000-7000-8000-000000000002', 'Beta', null, 'active', 'user', 'active', null),
                new UserMembership('m-3', '0197f1a0-0000-7000-8000-000000000003', 'Gamma', null, 'active', 'admin', 'suspended', null),
                new UserMembership('m-4', '0197f1a0-0000-7000-8000-000000000004', 'Delta', null, 'suspended', 'admin', 'active', null),
            ];
        }
    });
    app()->instance(MembershipPermissions::class, new class implements MembershipPermissions
    {
        public function forUser(int $userId, string $workspaceId): array
        {
            return [];
        }
    });

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('area', 'user');
    $request->session()->put('workspace_id', SHELL_WORKSPACE);

    $shell = app(ShellNavigation::class)->for($request);

    expect($shell['workspaces'])->toBe([
        ['id' => SHELL_WORKSPACE, 'name' => 'Acme Industries', 'label' => 'Acme <b>Production</b>', 'role' => 'admin'],
        ['id' => '0197f1a0-0000-7000-8000-000000000002', 'name' => 'Beta', 'label' => null, 'role' => 'user'],
    ])
        ->and($shell['workspace'])->toBe(['id' => SHELL_WORKSPACE, 'name' => 'Acme Industries', 'label' => 'Acme <b>Production</b>'])
        ->and($shell['switch_href'])->toBe('/workspaces/switch');
});

it('registers the switch as a named POST route behind auth, with no ID in the path', function () {
    $route = Router::getRoutes()->getByName('workspaces.switch');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('workspaces/switch')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain('auth');

    $this->post('/workspaces/switch', ['workspace_id' => (string) Str::uuid()])->assertRedirect('/login');
});

/** @param list<UserMembership> $memberships */
function shellWith(array $memberships, string $current = SHELL_WORKSPACE): array
{
    $user = new User(['name' => 'Ada', 'email' => 'ada@example.test']);
    $user->id = 7;

    app()->instance(MembershipLookup::class, new class($memberships) implements MembershipLookup
    {
        /** @param list<UserMembership> $memberships */
        public function __construct(private array $memberships) {}

        public function forUser(int $userId): array
        {
            return $this->memberships;
        }
    });

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('area', 'user');
    $request->session()->put('workspace_id', $current);

    return app(ShellNavigation::class)->for($request);
}

it('treats a session Workspace that is no longer active like no membership', function () {
    $shell = shellWith([new UserMembership('m-1', SHELL_WORKSPACE, 'Acme', null, 'suspended', 'admin', 'active', null)]);

    expect($shell['workspace'])->toBeNull()->and($shell['role'])->toBeNull()->and($shell['items'])->toBe([])->and($shell['workspaces'])->toBe([]);
});

it('sorts the switcher list by name, ignoring case, with more than 7 Workspaces', function () {
    $names = ['zeta', 'Alpha', 'beta', 'Eta', 'delta', 'Gamma', 'Theta', 'epsilon', 'Acme'];
    $memberships = [];

    foreach ($names as $i => $name) {
        $memberships[] = new UserMembership("m-{$i}", sprintf('0197f1a0-0000-7000-8000-0000000001%02d', $i), $name, null, 'active', 'user', 'active', null);
    }

    $shell = shellWith($memberships, $memberships[0]->workspaceId);

    expect(array_column($shell['workspaces'], 'name'))->toBe(['Acme', 'Alpha', 'beta', 'delta', 'epsilon', 'Eta', 'Gamma', 'Theta', 'zeta'])
        ->and($shell['workspaces'])->toHaveCount(9);
});

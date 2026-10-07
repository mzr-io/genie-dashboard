<?php

use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode as AccessErrorCode;
use App\Modules\Identity\Application\SwitchWorkspace;
use App\Modules\Identity\Application\WorkspaceSwitchRefused;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Modules\Identity\Http\WorkspaceSwitchController;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// The Story 1.17 Workspace switch against the real PostgreSQL: the membership re-read through the Access
// SECURITY DEFINER lookup, the target Workspace's own transaction and audit log, session rotation and
// the row-level-security 404.
beforeEach(fn () => $this->withoutVite());

const SWITCH_PASSWORD = 'a-long-enough-password';

/** @return array{0: int, 1: string} user ID and membership ID */
function switchMember(string $workspaceId, string $email, string $role, string $status = 'active'): array
{
    $existing = Cluster::rows(Cluster::superuser(), 'select id from users where email = ?', [$email]);
    $user = $existing === [] ? Cluster::user($email) : (int) $existing[0]['id'];
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(SWITCH_PASSWORD), $user]);

    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    return [$user, $membership];
}

function switchSignIn(string $email, string $role): void
{
    test()->postJson('/login', ['email' => $email, 'password' => SWITCH_PASSWORD, 'role' => $role])->assertOk();
}

function switchEvents(string $action): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at', [$action]);
}

function switchProps(string $url): array
{
    $props = null;

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];

        return $page;
    });

    return $props;
}

it('switches to another Workspace with the same role: keeps the area, audits in the new Workspace', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user, $memberA] = switchMember($a, 'ada@example.test', 'admin');
    [, $memberB] = switchMember($b, 'ada@example.test', 'admin');
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET last_active_at = now() - interval '1 day' WHERE id = ?")->execute([$memberA]);

    $this->startSession();
    switchSignIn('ada@example.test', 'admin');
    expect(session('workspace_id'))->toBe($a);
    $stampA = Cluster::rows(Cluster::superuser(), 'select last_active_at from workspace_memberships where id = ?', [$memberA])[0]['last_active_at'];

    $this->post(route('workspaces.switch'), ['workspace_id' => $b])->assertRedirect(route('admin.overview'));

    expect(session('workspace_id'))->toBe($b)
        ->and(session('area'))->toBe('admin');

    $events = switchEvents('identity.workspace.switched');
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($b)
        ->and($events[0]['security'])->toBeFalse()
        ->and($events[0]['actor'])->toBe($memberB)
        ->and($events[0]['subject'])->toBe('membership:'.$memberB)
        ->and(json_decode($events[0]['after_state'], true))->toEqualCanonicalizing([
            'user_id' => $user, 'membership_id' => $memberB, 'to_workspace_id' => $b, 'area' => 'admin',
        ]);

    // `last_active_at` moved for the chosen membership only (the sign-in stamped A once, the switch does not touch it).
    $rows = array_column(Cluster::rows(Cluster::superuser(), 'select id, last_active_at from workspace_memberships'), 'last_active_at', 'id');
    expect($rows[$memberB])->not->toBeNull()
        ->and($rows[$memberA])->toBe($stampA);

    // The next request reads the new Workspace.
    $shell = switchProps(route('admin.overview'))['shell'];
    expect($shell['workspace']['id'])->toBe($b)->and($shell['area'])->toBe('admin');
});

it('drops to the User area and the User Overview with workspace-role when the role there is only User', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'user');

    $this->startSession();
    switchSignIn('ada@example.test', 'admin');

    $this->post(route('workspaces.switch'), ['workspace_id' => $b])->assertRedirect(route('overview'));

    expect(session('workspace_id'))->toBe($b)->and(session('area'))->toBe('user');

    // The message names the real Workspace and is delivered once.
    $page = $this->get(route('overview'))->assertOk()->viewData('page');
    expect($page['flash'] ?? [])->toBe(['workspace_role' => ['workspace' => 'Beta']]);
    $page = $this->get(route('overview'))->assertOk()->viewData('page');
    expect($page['flash'] ?? [])->toBe([]);
});

it('keeps the User area for a User, even into a Workspace where they are Admin, with no message', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    switchMember($a, 'ada@example.test', 'user');
    switchMember($b, 'ada@example.test', 'admin');

    $this->startSession();
    switchSignIn('ada@example.test', 'user');

    $this->post(route('workspaces.switch'), ['workspace_id' => $b])->assertRedirect(route('overview'));

    expect(session('area'))->toBe('user');
    $page = $this->get(route('overview'))->assertOk()->viewData('page');
    expect($page['flash'] ?? [])->toBe([]);
});

it('refuses a forged, foreign, suspended or inactive Workspace with 403 access.workspace_forbidden and changes nothing', function (string $case) {
    $a = Cluster::workspace('Alpha');
    switchMember($a, 'ada@example.test', 'admin');
    $foreign = Cluster::workspace('Foreign');
    switchMember($foreign, 'someone@example.test', 'admin');
    $suspended = Cluster::workspace('Suspended');
    switchMember($suspended, 'ada@example.test', 'admin', 'suspended');
    $inactive = Cluster::workspace('Inactive', 'suspended');
    switchMember($inactive, 'ada@example.test', 'admin');

    $target = match ($case) {
        'unknown' => (string) Str::uuid7(),
        'foreign' => $foreign,
        'suspended membership' => $suspended,
        'inactive workspace' => $inactive,
        'malformed' => 'not-a-uuid',
        'missing' => null,
        'array' => ['x'],
    };

    $this->startSession();
    switchSignIn('ada@example.test', 'admin');

    $response = $this->postJson(route('workspaces.switch'), ['workspace_id' => $target]);

    $response->assertForbidden()->assertJsonPath('error.code', 'access.workspace_forbidden');
    expect(session('workspace_id'))->toBe($a)
        ->and(session('area'))->toBe('admin')
        ->and(switchEvents('identity.workspace.switched'))->toBe([]);

    $events = switchEvents('access.workspace.forbidden');
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($a)
        ->and($events[0]['security'])->toBeTrue()
        ->and(json_decode($events[0]['after_state'], true))->toMatchArray(['area' => 'admin', 'reason' => 'unusable_workspace']);
    // The forged ID is never stored.
    expect(json_encode($events[0]))->not->toContain((string) (is_string($target) ? $target : 'x-none'));
})->with(['unknown', 'foreign', 'suspended membership', 'inactive workspace', 'malformed', 'missing', 'array']);

it('answers a forged Workspace with 403 for an Inertia visit too, and writes a log line when the session has no Workspace', function () {
    $user = Cluster::user('nomad@example.test');

    Log::spy();
    $this->actingAs(User::query()->findOrFail($user))
        ->post(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()], ['X-Inertia' => 'true'])
        ->assertForbidden()->assertJsonPath('error.code', 'access.workspace_forbidden');

    Log::shouldHaveReceived('warning')->with('access.workspace.forbidden', ['reason' => 'no_workspace'])->once();
    expect(switchEvents('access.workspace.forbidden'))->toBe([]);
});

it('accepts the Workspace ID only in the body, never in the path or query', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    switchMember($a, 'ada@example.test', 'user');
    switchMember($b, 'ada@example.test', 'user');
    switchSignIn('ada@example.test', 'user');

    $this->post('/workspaces/switch/'.$b)->assertNotFound();
    $this->postJson('/workspaces/switch?workspace_id='.$b)->assertForbidden();

    expect(session('workspace_id'))->toBe($a);
});

it('keeps the pinned error code in step with Access', function () {
    expect(WorkspaceSwitchController::FORBIDDEN_CODE)->toBe(AccessErrorCode::WorkspaceForbidden->value);
});

it('refuses a guest', function () {
    $this->post(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()])->assertRedirect(route('login'));
});

it('lists only usable memberships on the shell prop, with label and role, and a single one without a list to switch', function () {
    $a = Cluster::workspace('Alpha', 'active', 'Acme Industries - Production');
    $b = Cluster::workspace('Beta');
    $suspended = Cluster::workspace('Gamma');
    $inactive = Cluster::workspace('Delta', 'suspended');
    $foreign = Cluster::workspace('Epsilon');
    switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'user');
    switchMember($suspended, 'ada@example.test', 'admin', 'suspended');
    switchMember($inactive, 'ada@example.test', 'admin');
    switchMember($foreign, 'other@example.test', 'admin');

    switchSignIn('ada@example.test', 'admin');
    $shell = switchProps(route('admin.overview'))['shell'];

    expect($shell['workspaces'])->toBe([
        ['id' => $a, 'name' => 'Alpha', 'label' => 'Acme Industries - Production', 'role' => 'admin'],
        ['id' => $b, 'name' => 'Beta', 'label' => null, 'role' => 'user'],
    ])
        ->and($shell['workspace'])->toBe(['id' => $a, 'name' => 'Alpha', 'label' => 'Acme Industries - Production'])
        ->and($shell['switch_href'])->toBe('/workspaces/switch');
});

it('answers 404 for an object ID of another Workspace in every tenant table that exists, and 200 for its own', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    switchMember($a, 'ada@example.test', 'user');

    Route::middleware(['web', 'auth'])->get('/_probe/{table}/{id}', function (string $table, string $id) {
        abort_unless(in_array($table, Cluster::tenantTables(), true), 404);

        return response()->json(['found' => DB::table($table)->where('id', $id)->firstOrFail()->id]);
    });

    switchSignIn('ada@example.test', 'user');

    $probed = 0;
    foreach (Cluster::tenantTables() as $table) {
        $own = Cluster::seedTenantRow($table, $a);
        $foreign = Cluster::seedTenantRow($table, $b);

        if ($own === null || $foreign === null) {
            $this->fail("Tenant table {$table} has no seeder in Cluster::seedTenantRow().");
        }

        $this->getJson("/_probe/{$table}/{$own}")->assertOk()->assertJsonPath('found', $own);
        $this->getJson("/_probe/{$table}/{$foreign}")->assertNotFound();
        $probed++;
    }

    expect($probed)->toBeGreaterThan(0);
});

/** A request with a real session store, so the session ID can be watched directly (the HTTP test client does not carry one). */
function switchRequest(int $user, string $workspaceId, string $area): Request
{
    $session = new Store('session', new ArraySessionHandler(120));
    $session->start();
    $session->put('workspace_id', $workspaceId);
    $session->put('area', $area);

    $request = Request::create('/workspaces/switch', 'POST');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => User::query()->findOrFail($user));

    return $request;
}

it('regenerates the session ID on a valid switch and leaves it alone on a refused one', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    $request = switchRequest($user, $a, 'admin');
    $before = $request->session()->getId();

    expect(fn () => app(SwitchWorkspace::class)->handle($request, (string) Str::uuid7()))->toThrow(WorkspaceSwitchRefused::class);
    expect($request->session()->getId())->toBe($before)->and($request->session()->get('workspace_id'))->toBe($a);

    $result = app(SwitchWorkspace::class)->handle($request, strtoupper($b));

    expect($request->session()->getId())->not->toBe($before)
        ->and($request->session()->get('workspace_id'))->toBe($b)
        ->and($request->session()->get('area'))->toBe('admin')
        ->and($result->downgraded)->toBeFalse();
});

it('does not move the session when the audit write fails', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    // The new Workspace's audit log refuses the row: the switch must not half-happen.
    Cluster::superuser()->exec("ALTER TABLE audit_events ADD CONSTRAINT no_switch CHECK (action <> 'identity.workspace.switched')");

    try {
        $request = switchRequest($user, $a, 'admin');
        $before = $request->session()->getId();

        expect(fn () => app(SwitchWorkspace::class)->handle($request, $b))->toThrow(QueryException::class);
        expect($request->session()->getId())->toBe($before)->and($request->session()->get('workspace_id'))->toBe($a);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE audit_events DROP CONSTRAINT no_switch');
    }

    $stamp = Cluster::rows(Cluster::superuser(), 'select last_active_at from workspace_memberships where workspace_id = ?', [$b])[0]['last_active_at'];
    expect($stamp)->toBeNull();
});

/** Wraps the real port so a test can act between the read and the write, or make a call fail. */
function switchSeam(?Closure $afterFirstRead = null, bool $markActive = true, bool $throwOnRead = false): void
{
    $real = app(SignInMemberships::class);
    $seam = new class($real, $afterFirstRead, $markActive, $throwOnRead) implements SignInMemberships
    {
        private int $reads = 0;

        public function __construct(private SignInMemberships $real, private ?Closure $afterFirstRead, private bool $markActive, private bool $throwOnRead) {}

        public function forUser(int $userId): array
        {
            if ($this->throwOnRead) {
                throw new RuntimeException('lookup down');
            }

            $result = $this->real->forUser($userId);

            if ($this->reads++ === 0 && $this->afterFirstRead !== null) {
                ($this->afterFirstRead)();
            }

            return $result;
        }

        public function markActive(string $workspaceId, string $membershipId): bool
        {
            return $this->markActive ? $this->real->markActive($workspaceId, $membershipId) : false;
        }
    };

    app()->instance(SignInMemberships::class, $seam);
}

it('does not leak the other Workspace ID into the new Workspace log', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    app(SwitchWorkspace::class)->handle(switchRequest($user, $a, 'admin'), $b);

    $row = switchEvents('identity.workspace.switched')[0];
    expect(json_encode($row))->not->toContain($a)->and($row['after_state'])->not->toContain('from_workspace_id');
});

it('refuses and changes nothing when the membership is suspended between the read and the write', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    [, $memberB] = switchMember($b, 'ada@example.test', 'admin');

    switchSeam(fn () => Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'suspended' WHERE id = ?")->execute([$memberB]));

    $request = switchRequest($user, $a, 'admin');
    $before = $request->session()->getId();

    expect(fn () => app(SwitchWorkspace::class)->handle($request, $b))->toThrow(WorkspaceSwitchRefused::class);
    expect($request->session()->getId())->toBe($before)
        ->and($request->session()->get('workspace_id'))->toBe($a)
        ->and(switchEvents('identity.workspace.switched'))->toBe([]);
});

it('refuses when the Workspace is deactivated between the read and the write', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    switchSeam(fn () => Cluster::superuser()->prepare("UPDATE workspaces SET status = 'suspended' WHERE id = ?")->execute([$b]));

    expect(fn () => app(SwitchWorkspace::class)->handle(switchRequest($user, $a, 'admin'), $b))->toThrow(WorkspaceSwitchRefused::class);
    expect(switchEvents('identity.workspace.switched'))->toBe([]);
});

it('refuses when the stamp updates no row', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    switchSeam(markActive: false);

    $request = switchRequest($user, $a, 'admin');

    expect(fn () => app(SwitchWorkspace::class)->handle($request, $b))->toThrow(WorkspaceSwitchRefused::class);
    expect($request->session()->get('workspace_id'))->toBe($a)->and(switchEvents('identity.workspace.switched'))->toBe([]);
});

it('invalidates the old session on a valid switch', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [$user] = switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');

    $request = switchRequest($user, $a, 'admin');
    $handler = Mockery::spy(SessionHandlerInterface::class);
    $store = new Store('session', $handler);
    $store->start();
    $store->put('workspace_id', $a);
    $store->put('area', 'admin');
    $request->setLaravelSession($store);
    $old = $store->getId();

    app(SwitchWorkspace::class)->handle($request, $b);

    $handler->shouldHaveReceived('destroy')->with($old)->once();
});

it('treats a switch to the already-active Workspace as a no-op to the area Overview', function () {
    $a = Cluster::workspace('Alpha');
    [$user, $memberA] = switchMember($a, 'ada@example.test', 'admin');

    $request = switchRequest($user, $a, 'admin');
    $before = $request->session()->getId();

    $result = app(SwitchWorkspace::class)->handle($request, $a);

    expect($request->session()->getId())->toBe($before)
        ->and($result->downgraded)->toBeFalse()
        ->and($result->area->value)->toBe('admin')
        ->and(switchEvents('identity.workspace.switched'))->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select last_active_at from workspace_memberships where id = ?', [$memberA])[0]['last_active_at'])->toBeNull();

    $this->startSession();
    switchSignIn('ada@example.test', 'admin');
    $this->post(route('workspaces.switch'), ['workspace_id' => $a])->assertRedirect(route('admin.overview'));
    expect(switchEvents('identity.workspace.switched'))->toBe([]);
});

it('answers a failing membership lookup with 403, a log line and no data', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    switchMember($a, 'ada@example.test', 'admin');
    switchMember($b, 'ada@example.test', 'admin');
    $this->startSession();
    switchSignIn('ada@example.test', 'admin');

    switchSeam(throwOnRead: true);
    Log::spy();

    $this->postJson(route('workspaces.switch'), ['workspace_id' => $b])
        ->assertForbidden()->assertJsonPath('error.code', 'access.workspace_forbidden');

    Log::shouldHaveReceived('error')->with('identity.workspace.lookup_failed', ['exception' => RuntimeException::class])->once();
    expect(session('workspace_id'))->toBe($a)->and(switchEvents('identity.workspace.switched'))->toBe([]);
});

it('records at most one forbidden event per person per minute', function () {
    $a = Cluster::workspace('Alpha');
    switchMember($a, 'ada@example.test', 'admin');
    $this->startSession();
    switchSignIn('ada@example.test', 'admin');

    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()])->assertForbidden();
    }

    expect(switchEvents('access.workspace.forbidden'))->toHaveCount(1);
});

it('throttles the switch route to 30 requests a minute', function () {
    $a = Cluster::workspace('Alpha');
    switchMember($a, 'ada@example.test', 'admin');
    $this->startSession();
    switchSignIn('ada@example.test', 'admin');

    foreach (range(1, 30) as $ignored) {
        $this->postJson(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()])->assertForbidden();
    }

    $this->postJson(route('workspaces.switch'), ['workspace_id' => (string) Str::uuid7()])->assertStatus(429);
});

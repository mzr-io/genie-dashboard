<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Navigation\ShellNavigation;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.19 against the real PostgreSQL: the gate reads membership and membership_permissions fresh on
// every request under row-level security, denials are audited on the security connection, and the matrix is
// generated from the router.
beforeEach(fn () => $this->withoutVite());

/** @return array{0: int, 1: string} user ID and membership ID */
function gateMember(string $workspaceId, string $email, string $role, array $permissions = [], string $status = 'active'): array
{
    $user = Cluster::user($email);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        gateGrant($workspaceId, $membership, $permission);
    }

    return [$user, $membership];
}

function gateGrant(string $workspaceId, string $membership, string $permission): void
{
    Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
}

function gateAs(int $user, string $workspaceId, string $area): void
{
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => $area]);
}

function gateDenials(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'access.admin.denied' order by occurred_at");
}

/** @return list<array{0: string, 1: string, 2: string, 3: ?Permission}> method, uri, name, permission of every route using `admin` */
function gateRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $key = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'admin' || str_starts_with($middleware, 'admin:'))) {
                $key = $middleware === 'admin' ? '' : substr($middleware, 6);
            }
        }

        if ($key === null) {
            continue;
        }

        $permission = $key === '' ? ShellNavigation::permissionForRoute((string) $route->getName()) : Permission::from($key);
        $found[] = [$route->methods()[0], '/'.$route->uri(), (string) $route->getName(), $permission];
    }

    return $found;
}

/** Requests a route the way its client does: a page as an Inertia visit, an API route as JSON. */
function gateCall(string $method, string $uri)
{
    // A route parameter is a member that does not exist (the allowed case then answers 404, the gate's 403 is what matters).
    $uri = str_replace(['{membership}', '{invitation}', '{group}', '{entry}'], (string) Str::uuid7(), $uri);

    if (str_starts_with($uri, '/api/')) {
        $headers = ['Referer' => 'http://localhost:8000'];

        return match ($method) {
            'GET' => test()->getJson($uri, $headers),
            'DELETE' => test()->deleteJson($uri, [], $headers),
            'PATCH' => test()->patchJson($uri, [], $headers),
            default => test()->postJson($uri, [], $headers),
        };
    }

    return test()->get($uri, [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ]);
}

function gateAssertDenied(string $label, $response, string $uri): void
{
    expect($response->status())->toBe(403, $label);

    if (str_starts_with($uri, '/api/')) {
        expect($response->json('error.code'))->toBe(ErrorCode::NotAuthorized->value, $label);

        return;
    }

    // The Forbidden page, and nothing of the denied page's own props.
    expect($response->json('component'))->toBe('Forbidden', $label)
        ->and($response->json('props'))->not->toHaveKey('page', $label);
}

it('covers every Admin route for User area, Admin without the permission, Admin without only its own, demoted and allowed', function () {
    // Invitations need the lifetime: without it the write routes answer 422 before looking for the invitation.
    config(['dashflow.tunables.users.invitation_lifetime.value' => '48']);
    $workspace = Cluster::workspace('Acme');
    [$both] = gateMember($workspace, 'both@example.test', 'admin', Permission::values());
    [$none] = gateMember($workspace, 'none@example.test', 'admin');
    [$demoted] = gateMember($workspace, 'demoted@example.test', 'user', Permission::values());
    [$suspended] = gateMember($workspace, 'suspended@example.test', 'admin', Permission::values(), 'suspended');
    $routes = gateRoutes();
    $denials = fn (): int => count(gateDenials());

    // Eleven pages (ADMIN_ITEMS), the pages that are views of an item (ADMIN_PAGES), the two API probes and the member,
    // invitation, group and host allowlist endpoints (ADMIN_API_ROUTES).
    expect($routes)->toHaveCount(count(ShellNavigation::ADMIN_ITEMS) + count(ShellNavigation::ADMIN_PAGES) + 2 + count(ShellNavigation::ADMIN_API_ROUTES));

    foreach ($routes as $n => [$method, $uri, $name, $permission]) {
        // Each denied case must add exactly one audit row, so the per-minute de-duplication is reset.
        $denied = function (string $label, int $user, string $area) use ($workspace, $method, $uri, $denials) {
            Cache::flush();
            $before = $denials();
            gateAs($user, $workspace, $area);
            gateAssertDenied($label, gateCall($method, $uri), $uri);
            expect($denials())->toBe($before + 1, "{$label}: audited");
        };

        $denied("{$name}: User area", $both, 'user');
        $denied("{$name}: demoted to user", $demoted, 'admin');

        // A membership that is not active ends the session before the gate: 401, no denial row.
        Cache::flush();
        $before = $denials();
        gateAs($suspended, $workspace, 'admin');
        expect(gateCall($method, $uri)->status())->toBe(401, "{$name}: suspended membership")
            ->and($denials())->toBe($before, "{$name}: suspended is signed out, not denied");

        // A route with no permission needs the Admin area only.
        if ($permission !== null) {
            $denied("{$name}: Admin without {$permission->value}", $none, 'admin');

            [$others] = gateMember($workspace, "others{$n}@example.test", 'admin', array_values(array_diff(Permission::values(), [$permission->value])));
            $denied("{$name}: Admin with every permission except {$permission->value}", $others, 'admin');
        }

        // Admin with it: allowed, and nothing audited.
        Cache::flush();
        $before = $denials();
        gateAs($both, $workspace, 'admin');
        // Past the gate: a page or read is 200, a route naming an ID that does not exist 404 and an empty create 422 (never 403).
        $status = gateCall($method, $uri)->status();
        expect($status)->toBe(match (true) {
            // The member update validates its body (the revision) before it looks for the member.
            $name === 'api.admin.members.update' => 422,
            // Deactivate and reactivate validate the revision first, too.
            in_array($name, ['api.admin.members.deactivate', 'api.admin.members.reactivate'], true) => 422,
            // A group name is validated before the group is looked up.
            in_array($name, ['api.admin.groups.store', 'api.admin.groups.update'], true) => 422,
            // A host and revision are validated before the allowlist is touched; a removal validates its revision before the entry.
            in_array($name, ['api.admin.host-allowlist.store', 'api.admin.host-allowlist.destroy'], true) => 422,
            str_contains($uri, '{') => 404,
            $name === 'api.admin.invitations.store' => 422,
            default => 200,
        }, "{$name}: Admin with permission")
            ->and($denials())->toBe($before, "{$name}: allowed is not audited");
    }
});

it('still audits when the cache store fails', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = gateMember($workspace, 'ada@example.test', 'admin');

    gateAs($user, $workspace, 'admin');
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    $this->get(route('admin.users.index'))->assertForbidden();

    expect(gateDenials())->toHaveCount(1);
});

it('renders the Forbidden page with perm-denied and audits access.admin.denied with route and actor', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = gateMember($workspace, 'ada@example.test', 'admin', Permission::values());

    gateAs($user, $workspace, 'user');
    $this->get(route('admin.users.index'))->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('Forbidden')->missing('page'));

    $events = gateDenials();
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and($events[0]['security'])->toBeTrue()
        ->and($events[0]['actor'])->toBe($membership)
        ->and($events[0]['subject'])->toBe('membership:'.$membership);

    $state = json_decode($events[0]['after_state'], true);
    expect($state['route'])->toBe('admin.users.index')
        ->and($state['reason'])->toBe('area')
        ->and($state['permission'])->toBe('users.manage')
        ->and($state['membership_id'])->toBe($membership);
});

it('audits at most one denial per person, route and minute', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = gateMember($workspace, 'ada@example.test', 'admin');
    Cache::flush();

    gateAs($user, $workspace, 'admin');
    $this->get(route('admin.users.index'))->assertForbidden();
    $this->get(route('admin.users.index'))->assertForbidden();
    expect(gateDenials())->toHaveCount(1);

    $this->get(route('admin.audit.index'))->assertForbidden();
    expect(gateDenials())->toHaveCount(2);
});

it('answers the API with access.not_authorized and audits it', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = gateMember($workspace, 'ada@example.test', 'admin');
    Cache::flush();

    gateAs($user, $workspace, 'admin');
    $this->getJson('/api/v1/admin/ping', ['Referer' => 'http://localhost:8000'])
        ->assertForbidden()
        ->assertJsonPath('error.code', ErrorCode::NotAuthorized->value);

    expect(gateDenials())->toHaveCount(1);
});

it('denies the next request after a permission is revoked (nothing is cached)', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = gateMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    Cache::flush();

    gateAs($user, $workspace, 'admin');
    $this->get(route('admin.users.index'))->assertOk();

    Cluster::superuser()->prepare('DELETE FROM membership_permissions WHERE membership_id = ?')->execute([$membership]);

    $this->get(route('admin.users.index'))->assertForbidden();
});

it('denies an Admin-area session whose membership was demoted, suspended or whose Workspace went inactive', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = gateMember($workspace, 'ada@example.test', 'admin', ['users.manage']);
    Cache::flush();

    gateAs($user, $workspace, 'admin');
    $this->get(route('admin.users.index'))->assertOk();

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);
    $this->get(route('admin.users.index'))->assertForbidden();
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'admin', status = 'suspended' WHERE id = ?")->execute([$membership]);
    // Not active: the session ends (redirect to sign-in) before the gate.
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'active' WHERE id = ?")->execute([$membership]);
    gateAs($user, $workspace, 'admin');
    $this->get(route('admin.users.index'))->assertOk();
    Cluster::superuser()->prepare("UPDATE workspaces SET status = 'suspended' WHERE id = ?")->execute([$workspace]);
    $this->get(route('admin.users.index'))->assertForbidden();
});

it('never trusts a session Workspace the person is not a member of', function () {
    $mine = Cluster::workspace('Mine');
    $other = Cluster::workspace('Other');
    [$user] = gateMember($mine, 'ada@example.test', 'admin', Permission::values());
    gateMember($other, 'bob@example.test', 'admin', Permission::values());

    gateAs($user, $other, 'admin');
    // No membership there: the session ends before the gate (and nothing is audited in the other Workspace).
    $this->get(route('admin.overview'))->assertRedirect(route('login'));
    expect(gateDenials())->toBe([]);
});

it('shares a can map that is true only for held permissions in the Admin area', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = gateMember($workspace, 'ada@example.test', 'admin', ['blocks.edit']);

    $can = function (string $url): array {
        $can = null;
        test()->get($url)->assertOk()->assertInertia(function ($page) use (&$can) {
            $can = $page->toArray()['props']['shell']['can'];

            return $page;
        });

        return $can;
    };

    gateAs($user, $workspace, 'admin');
    $held = $can(route('admin.blocks.index'));
    expect($held)->toHaveCount(count(Permission::cases()))
        ->and(array_keys(array_filter($held)))->toBe(['blocks.edit'])
        ->and($held['blocks.publish'])->toBeFalse();

    gateAs($user, $workspace, 'user');
    expect(array_filter($can(route('overview'))))->toBe([]);
});

it('keeps membership_permissions a closed set under row-level security', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    [, $memberA] = gateMember($a, 'a@example.test', 'admin', ['users.manage']);
    gateMember($b, 'b@example.test', 'admin', ['audit.view']);

    $tables = Cluster::rows(Cluster::superuser(), "select relrowsecurity, relforcerowsecurity from pg_class where relname = 'membership_permissions'");
    expect($tables[0]['relrowsecurity'])->toBeTrue()->and($tables[0]['relforcerowsecurity'])->toBeTrue();

    // An unknown permission is refused by the CHECK constraint.
    expect(fn () => gateGrant($a, $memberA, 'users.everything'))->toThrow(PDOException::class);

    // Role app sees only the context Workspace's rows, and none with no context.
    $app = Cluster::directApp();
    expect(Cluster::rows($app, 'select permission from membership_permissions'))->toBe([]);
    $app->beginTransaction();
    $app->prepare("select set_config('app.workspace_id', ?, true)")->execute([$a]);
    expect(array_column(Cluster::rows($app, 'select permission from membership_permissions'), 'permission'))->toBe(['users.manage']);
    $app->rollBack();
});

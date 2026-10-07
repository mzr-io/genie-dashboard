<?php

use App\Http\Middleware\RequireAdminAccess;
use App\Http\Navigation\ShellNavigation;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\UserMembership;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Story 1.19 on the SQLite Feature suite: denial responses, fail-closed paths and the CSRF order. The real
// membership and permission reads, the audit rows and the generated matrix run in the Database suite.
beforeEach(fn () => $this->withoutVite());

it('answers a User-area session with the 403 Forbidden page and no page content', function () {
    Log::spy();

    $this->actingAs(User::factory()->create())->get(route('admin.users.index'))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Forbidden')
            ->missing('page'));

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'access.admin.denied' && $context['reason'] === 'no_workspace' && $context['route'] === 'admin.users.index' && isset($context['user_id']) && array_key_exists('request_id', $context))->once();
});

it('answers the API with the error envelope access.not_authorized', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost:8000')
        ->getJson('/api/v1/admin/ping')
        ->assertForbidden()
        ->assertJsonPath('error.code', ErrorCode::NotAuthorized->value)
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('sends a guest to sign-in and answers an API guest with 401', function () {
    $this->get(route('admin.overview'))->assertRedirect(route('login'));
    $this->getJson('/api/v1/admin/ping')->assertUnauthorized();
});

it('rejects a state-changing Admin request without X-XSRF-TOKEN with 419 before authorization', function () {
    app()['env'] = 'local';
    Log::spy();

    Route::middleware(['web', 'auth', 'admin:settings.manage'])->post('/_probe/admin', fn () => 'ok')->name('probe.admin');

    $this->actingAs(User::factory()->create())->post('/_probe/admin')->assertStatus(419);
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/api/v1/admin/ping')
        ->assertStatus(419);

    // The gate never ran: it would have logged a denial.
    Log::shouldNotHaveReceived('warning');
});

it('fails closed on a mistyped key or an unmapped admin route: 403 and access.admin.unmapped_route', function () {
    Log::spy();

    Route::middleware(['web', 'auth', 'admin:nope.nothing'])->get('/_probe/bad', fn () => 'ok')->name('probe.bad');
    Route::middleware(['web', 'auth', 'admin'])->get('/_probe/unmapped', fn () => 'ok')->name('probe.unmapped');
    Route::middleware(['web', 'auth', 'admin'])->get('/_probe/unnamed', fn () => 'ok');

    $user = User::factory()->create();

    foreach (['/_probe/bad', '/_probe/unmapped', '/_probe/unnamed'] as $uri) {
        $this->actingAs($user)->get($uri)->assertForbidden();
    }

    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'access.admin.unmapped_route')->times(3);
});

it('lets a state-changing request WITH a valid XSRF token reach the gate, where it is denied 403', function () {
    app()['env'] = 'local';
    Log::spy();

    Route::middleware(['web', 'auth', 'admin:settings.manage'])->post('/_probe/admin', fn () => 'ok')->name('probe.admin');

    $this->actingAs(User::factory()->create())
        ->withSession(['_token' => 'valid-token'])
        ->withHeader('X-CSRF-TOKEN', 'valid-token')
        ->post('/_probe/admin')
        ->assertForbidden();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'access.admin.denied')->once();
});

/**
 * Runs the middleware directly with an Admin-area session for an active admin membership (no database
 * transaction involved). A Throwable argument makes that read throw.
 *
 * @return array{0: Response, 1: string, 2: int} the response, the Workspace ID and the user ID
 */
function runGate(?Throwable $lookupFails = null, ?Throwable $permissionsFail = null): array
{
    $workspaceId = (string) Str::uuid();
    $user = User::factory()->create();

    app()->instance(MembershipLookup::class, new class($workspaceId, $lookupFails) implements MembershipLookup
    {
        public function __construct(private string $workspaceId, private ?Throwable $fails) {}

        public function forUser(int $userId): array
        {
            throw_if($this->fails);

            return [new UserMembership('m1', $this->workspaceId, 'Acme', null, 'active', 'admin', 'active', null)];
        }
    });
    app()->instance(MembershipPermissions::class, new class($permissionsFail) implements MembershipPermissions
    {
        public function __construct(private ?Throwable $fails) {}

        public function forUser(int $userId, string $workspaceId): array
        {
            throw_if($this->fails);

            return [];
        }
    });

    $request = Request::create('/admin/users', 'GET');
    $request->setRouteResolver(fn () => (new RoutingRoute('GET', '/admin/users', fn () => 'ok'))->name('admin.users.index'));
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(tap(app('session.store'))->put(['workspace_id' => $workspaceId, 'area' => 'admin']));

    return [app(RequireAdminAccess::class)->handle($request, fn () => response('allowed')), $workspaceId, $user->id];
}

it('fails closed when the membership lookup throws', function () {
    Log::spy();

    [$response] = runGate(lookupFails: new RuntimeException('down'));

    expect($response->getStatusCode())->toBe(403);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'access.admin.lookup_failed')->once();
});

it('fails closed when the permission read throws', function () {
    Log::spy();

    [$response] = runGate(permissionsFail: new RuntimeException('down'));

    expect($response->getStatusCode())->toBe(403);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'access.admin.lookup_failed')->once();
});

it('does not silence the next denial for a minute when the audit write fails', function () {
    Log::spy();
    Cache::flush();

    // No security connection or audit tables on the Feature database: the write fails.
    [$response, $workspaceId, $userId] = runGate();

    expect($response->getStatusCode())->toBe(403);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'audit.security.failed')->once();
    expect(Cache::has(TenantKey::cache($workspaceId, 'admin-denied:'.$userId.':admin.users.index')))->toBeFalse();
});

it('maps routes to permissions through ShellNavigation::ADMIN_ITEMS only', function () {
    foreach (ShellNavigation::ADMIN_ITEMS as [$route, $permission]) {
        expect(ShellNavigation::permissionForRoute($route))->toBe($permission);
    }

    expect(ShellNavigation::permissionForRoute('overview'))->toBeNull()
        ->and(class_exists(RequireAdminAccess::class))->toBeTrue();
});

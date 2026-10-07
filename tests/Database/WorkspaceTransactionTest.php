<?php

use App\Modules\Access\Infrastructure\WorkspaceMembership;
use App\Platform\Tenancy\Workspace;
use App\Platform\Tenancy\WorkspaceContext;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContextMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\Database\Support\Cluster;

it('opens one transaction and sets the transaction-local context', function () {
    $a = Cluster::workspace('A');
    $transaction = app(WorkspaceTransaction::class);

    $inside = $transaction->run($a, fn () => [
        DB::transactionLevel(),
        DB::selectOne("select current_setting('app.workspace_id', true) as ctx")->ctx,
        app(WorkspaceContext::class)->workspaceId(),
    ]);

    expect($inside)->toBe([1, $a, $a])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(app(WorkspaceContext::class)->workspaceId())->toBeNull()
        ->and(DB::selectOne("select nullif(current_setting('app.workspace_id', true), '') as ctx")->ctx)->toBeNull();
});

it('rolls back on an exception and clears the context', function () {
    $a = Cluster::workspace('A');
    $user = Cluster::user('tx@example.test');
    $transaction = app(WorkspaceTransaction::class);

    expect(fn () => $transaction->run($a, function () use ($a, $user) {
        WorkspaceMembership::create(['workspace_id' => $a, 'user_id' => $user, 'role' => 'user', 'status' => 'active']);
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(0)
        ->and(app(WorkspaceContext::class)->workspaceId())->toBeNull();
});

it('refuses to move a running transaction to another Workspace and lets the same one nest', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $transaction = app(WorkspaceTransaction::class);

    expect(fn () => $transaction->run($a, fn () => $transaction->run($b, fn () => null)))->toThrow(LogicException::class);
    expect($transaction->run($a, fn () => $transaction->run($a, fn () => 'ok')))->toBe('ok');
    expect(fn () => $transaction->run('nope', fn () => null))->toThrow(InvalidArgumentException::class);
});

it('creates Workspaces and memberships with UUIDv7 keys generated in the app', function () {
    $workspace = Workspace::on('migrator')->create(['name' => 'Acme', 'label' => 'Acme Ltd']);
    $user = Cluster::user('uuid@example.test');

    $membership = app(WorkspaceTransaction::class)->run($workspace->id, fn () => WorkspaceMembership::create([
        'workspace_id' => $workspace->id, 'user_id' => $user, 'role' => 'admin', 'status' => 'active',
    ]));

    foreach ([$workspace->id, $membership->id] as $id) {
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }

    expect(Cluster::rows(Cluster::superuser(), 'select id from workspace_memberships')[0]['id'])->toBe($membership->id);
});

it('runs a request in its session Workspace through the middleware', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    Cluster::seedTenantRow('workspace_memberships', $b);

    $middleware = app(WorkspaceTransaction::class);
    $read = fn () => new Response(json_encode(DB::table('workspace_memberships')->pluck('id')->all()));

    $withContext = Request::create('/x');
    $withContext->attributes->set(WorkspaceTransaction::REQUEST_ATTRIBUTE, $a);
    expect($middleware->handle($withContext, $read)->getContent())->toBe(json_encode([$rowA]));

    $without = Request::create('/x');
    expect($middleware->handle($without, $read)->getContent())->toBe('[]');

    $garbage = Request::create('/x');
    $garbage->attributes->set(WorkspaceTransaction::REQUEST_ATTRIBUTE, "'; drop table users; --");
    expect($middleware->handle($garbage, $read)->getContent())->toBe('[]');
});

it('rolls a request back when it ends in a 4xx or 5xx response', function (int $status) {
    $a = Cluster::workspace('A');
    $user = Cluster::user('fail@example.test');

    $request = Request::create('/x');
    $request->attributes->set(WorkspaceTransaction::REQUEST_ATTRIBUTE, $a);

    $response = app(WorkspaceTransaction::class)->handle($request, function () use ($a, $user, $status) {
        WorkspaceMembership::create(['workspace_id' => $a, 'user_id' => $user, 'role' => 'user', 'status' => 'active']);

        return new Response('error', $status);
    });

    expect($response->getStatusCode())->toBe($status)
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(0);
})->with([403, 422, 500]);

it('commits a request that ends below 400', function () {
    $a = Cluster::workspace('A');
    $user = Cluster::user('ok@example.test');

    $request = Request::create('/x');
    $request->attributes->set(WorkspaceTransaction::REQUEST_ATTRIBUTE, $a);

    app(WorkspaceTransaction::class)->handle($request, function () use ($a, $user) {
        WorkspaceMembership::create(['workspace_id' => $a, 'user_id' => $user, 'role' => 'user', 'status' => 'active']);

        return new Response('ok', 302);
    });

    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(1);
});

it('resolves the Workspace from the session key and sees only its rows', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    Cluster::seedTenantRow('workspace_memberships', $b);

    $session = fn (array $data): Store => tap(new Store('test', new ArraySessionHandler(10)), fn (Store $store) => $store->put($data));
    $read = fn () => new Response(json_encode(DB::table('workspace_memberships')->pluck('id')->all()));
    $middleware = app(WorkspaceTransaction::class);

    $with = Request::create('/x');
    $with->setLaravelSession($session([WorkspaceTransaction::SESSION_KEY => $a]));
    expect($middleware->handle($with, $read)->getContent())->toBe(json_encode([$rowA]));

    $without = Request::create('/x');
    $without->setLaravelSession($session(['other' => 'value']));
    expect($middleware->handle($without, $read)->getContent())->toBe('[]');

    $malformed = Request::create('/x');
    $malformed->setLaravelSession($session([WorkspaceTransaction::SESSION_KEY => 'nope']));
    expect($middleware->handle($malformed, $read)->getContent())->toBe('[]');
});

it('sits after StartSession in the web group', function () {
    $web = app('router')->getMiddlewareGroups()['web'];

    expect($web)->toContain(StartSession::class, WorkspaceTransaction::class)
        ->and(array_search(WorkspaceTransaction::class, $web, true))->toBeGreaterThan(array_search(StartSession::class, $web, true));
});

it('is registered on the web and api middleware groups, after the request context', function () {
    $router = app('router');

    foreach (['web', 'api'] as $group) {
        expect($router->getMiddlewareGroups()[$group])->toContain(WorkspaceTransaction::class);
    }

    expect(app(Kernel::class)->hasMiddleware(RequestContextMiddleware::class))->toBeTrue();
});

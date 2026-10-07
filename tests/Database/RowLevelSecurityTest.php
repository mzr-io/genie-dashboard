<?php

use App\Modules\Access\Contracts\MembershipLookup;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Database\Support\LeakChecks;

$dependencies = require dirname(__DIR__).'/Architecture/dependencies.php';

it('splits every table into global (listed) or tenant (workspace_id)', function () use ($dependencies) {
    $tenant = Cluster::tenantTables();
    $nonTenant = array_values(array_diff(Cluster::allTables(), $tenant, ['migrations']));

    expect($tenant)->toContain('workspace_memberships');

    foreach ($nonTenant as $table) {
        expect(in_array($table, $dependencies['global_tables'], true))->toBeTrue("table {$table} has no workspace_id but is not a global table");
    }
});

it('protects every tenant table with non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the context policy', function () {
    foreach (Cluster::tenantTables() as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags['relrowsecurity'])->toBeTrue("{$table}: ENABLE ROW LEVEL SECURITY")
            ->and($flags['relforcerowsecurity'])->toBeTrue("{$table}: FORCE ROW LEVEL SECURITY");

        $column = Cluster::rows(Cluster::superuser(), "select is_nullable from information_schema.columns where table_name = ? and column_name = 'workspace_id'", [$table])[0];
        expect($column['is_nullable'])->toBe('NO', "{$table}.workspace_id must be NOT NULL");

        $policies = array_column(Cluster::rows(Cluster::superuser(), 'select qual from pg_policies where tablename = ? and cmd in (\'ALL\', \'SELECT\') and roles = \'{public}\'', [$table]), 'qual');
        expect(str_contains(implode(' ', $policies), "current_setting('app.workspace_id'::text, true)"))->toBeTrue("{$table}: workspace policy");
    }
});

it('returns zero rows from every tenant table when no context is set', function () {
    foreach (LeakChecks::tables() as $table) {
        LeakChecks::noContext($table);
    }
});

it('never returns another Workspace\'s rows from any tenant table', function () {
    foreach (LeakChecks::tables() as $table) {
        LeakChecks::crossWorkspace($table);
    }
});

it('refuses to write a row into another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $user = Cluster::user('writer@example.test');

    expect(fn () => Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "insert into workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) values (?, ?, ?, 'user', 'active', now(), now())", [(string) Str::uuid7(), $b, $user])))
        ->toThrow(PDOException::class, 'row-level security');

    expect(fn () => Cluster::rows(Cluster::pooledApp(), "insert into workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) values (?, ?, ?, 'user', 'active', now(), now())", [(string) Str::uuid7(), $a, $user]))
        ->toThrow(PDOException::class, 'row-level security');
});

it('turns row_security off into an error rather than a bypass', function () {
    $a = Cluster::workspace('A');
    Cluster::seedTenantRow('workspace_memberships', $a);

    $app = Cluster::directApp();
    $app->exec('set row_security = off');

    expect(fn () => Cluster::rows($app, 'select * from workspace_memberships'))->toThrow(PDOException::class, 'row-level security');
});

it('keeps workspaces behind the Access function: app has no privilege on it', function () {
    Cluster::workspace('A');

    expect(fn () => Cluster::rows(Cluster::directApp(), 'select * from workspaces'))->toThrow(PDOException::class, 'permission denied');
    expect(fn () => Cluster::rows(Cluster::directApp(), "insert into workspaces (id, name) values (gen_random_uuid(), 'x')"))->toThrow(PDOException::class, 'permission denied');
});

it('looks up a user\'s memberships across Workspaces only through the function', function () {
    $a = Cluster::workspace('Alpha');
    $b = Cluster::workspace('Beta');
    $c = Cluster::workspace('Gamma');
    $user = Cluster::user('multi@example.test');
    $other = Cluster::user('other@example.test');

    foreach ([[$a, $user, 'admin'], [$b, $user, 'user'], [$c, $other, 'user']] as [$ws, $u, $role]) {
        Cluster::superuser()->prepare("insert into workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) values (?, ?, ?, ?, 'active', now(), now())")
            ->execute([(string) Str::uuid7(), $ws, $u, $role]);
    }

    $memberships = app(MembershipLookup::class)->forUser($user);

    expect(array_map(fn ($m) => [$m->workspaceName, $m->workspaceId, $m->role], $memberships))
        ->toBe([['Alpha', $a, 'admin'], ['Beta', $b, 'user']]);

    // The function leaves no context behind, and unrelated users stay invisible.
    expect(Cluster::rows(Cluster::directApp(), 'select * from workspace_memberships'))->toBe([])
        ->and(app(MembershipLookup::class)->forUser(999999))->toBe([]);
});

it('does not let app open the lookup policy itself', function () {
    $a = Cluster::workspace('A');
    $user = Cluster::user('lookup@example.test');
    Cluster::superuser()->prepare("insert into workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) values (?, ?, ?, 'user', 'active', now(), now())")
        ->execute([(string) Str::uuid7(), $a, $user]);

    $app = Cluster::directApp();
    Cluster::rows($app, "select set_config('app.lookup_user_id', ?, false)", [(string) $user]);

    expect(Cluster::rows($app, 'select * from workspace_memberships'))->toBe([]);
});

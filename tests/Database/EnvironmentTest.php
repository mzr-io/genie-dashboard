<?php

use App\Support\Health\HealthChecker;
use App\Support\Health\Role;
use Tests\Database\Support\Cluster;

it('runs PostgreSQL 18', function () {
    $version = Cluster::rows(Cluster::superuser(), 'show server_version_num')[0]['server_version_num'];

    expect((int) $version)->toBeGreaterThanOrEqual(180000)->toBeLessThan(190000);
});

it('runs PgBouncer in transaction mode with one server connection per pool', function () {
    $admin = Cluster::pgbouncerAdmin();

    $version = $admin->query('SHOW VERSION')->fetchColumn();
    preg_match('/(\d+)\.(\d+)/', (string) $version, $m);
    expect(((int) $m[1]) * 100 + (int) $m[2])->toBeGreaterThanOrEqual(121);

    $config = array_column($admin->query('SHOW CONFIG')->fetchAll(), 'value', 'key');
    expect($config['pool_mode'])->toBe('transaction')
        ->and((int) $config['default_pool_size'])->toBe(1)
        ->and((int) $config['max_prepared_statements'])->toBeGreaterThan(0);
});

it('has the five roles, none able to bypass row-level security', function () {
    $roles = Cluster::rows(Cluster::superuser(), "select rolname, rolsuper, rolbypassrls from pg_roles where rolname in ('app','migrator','maintenance','system','operator') order by rolname");

    expect(array_column($roles, 'rolname'))->toBe(['app', 'maintenance', 'migrator', 'operator', 'system']);

    foreach ($roles as $role) {
        expect($role['rolbypassrls'])->toBeFalse("{$role['rolname']} must not have BYPASSRLS")
            ->and($role['rolsuper'])->toBeFalse("{$role['rolname']} must not be a superuser");
    }

    $bypassing = Cluster::rows(Cluster::superuser(), 'select rolname from pg_roles where rolbypassrls or rolsuper order by rolname');
    expect(array_column($bypassing, 'rolname'))->toBe(['postgres']);
});

it('connects the application as app and migrations as migrator', function () {
    expect(DB::selectOne('select current_user as u')->u)->toBe('app')
        ->and(DB::connection('migrator')->selectOne('select current_user as u')->u)->toBe('migrator');

    $owners = Cluster::rows(Cluster::superuser(), "select tableowner from pg_tables where schemaname = 'public' group by tableowner");
    expect(array_column($owners, 'tableowner'))->toBe(['migrator']);
});

it('fails when the database cannot be reached', function () {
    expect(fn () => Cluster::connect('app', 'dashflow-test-app', 1))->toThrow(PDOException::class);
});

it('reports a healthy role for app and an unhealthy one for a role that can bypass', function () {
    $report = app(HealthChecker::class)->check(Role::Web);

    expect($report->failures)->not->toHaveKey(HealthChecker::DATABASE_ROLE)
        ->and($report->failures)->not->toHaveKey(HealthChecker::POSTGRESQL);

    config(['database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => env('DASHFLOW_TEST_SUPERUSER_PASSWORD'), 'database.connections.pgsql.port' => Cluster::postgresPort()]);
    DB::purge('pgsql');

    try {
        $report = app(HealthChecker::class)->check(Role::Web);
        expect($report->failures)->toHaveKey(HealthChecker::DATABASE_ROLE);
    } finally {
        DB::purge('pgsql');
    }
});

it('refuses to run against any database but the test one', function () {
    $good = [
        'DB_DATABASE' => 'dashflow_test', 'DB_PORT' => '56432', 'DB_HOST' => '127.0.0.1',
        'DB_MIGRATOR_PORT' => '55432', 'DB_MIGRATOR_HOST' => '127.0.0.1',
    ];

    Cluster::guard($good);
    Cluster::guard($good + ['DB_MIGRATOR_DATABASE' => 'dashflow_test']);

    foreach ([
        ['DB_DATABASE' => 'dashflow'],
        ['DB_DATABASE' => null],
        ['DB_PORT' => '5432'],
        ['DB_MIGRATOR_PORT' => '5432'],
        ['DB_HOST' => 'postgres'],
        ['DB_MIGRATOR_HOST' => 'db.internal'],
        ['DB_MIGRATOR_DATABASE' => 'dashflow'],
    ] as $override) {
        expect(fn () => Cluster::guard($override + $good))->toThrow(RuntimeException::class, 'Refusing to run the Database suite');
    }

    // The real environment is the test one.
    Cluster::guard();
});

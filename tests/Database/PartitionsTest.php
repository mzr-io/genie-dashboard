<?php

use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.5: `sync_runs` is the first partitioned table. Every partition is a tenant table of its own: it must hold the same
// row-level security as the parent (a partition queried directly is checked by its own policy), and the idempotent ensure
// step must create new months with that security. The leak tests list partitions through Cluster::partitions().

/** @return list<string> */
function ptPartitions(string $parent = 'sync_runs'): array
{
    return array_column(array_filter(Cluster::partitions(), fn (array $p): bool => $p['parent'] === $parent), 'partition');
}

function ptMonth(int $offset): string
{
    return gmdate('Y-m-01', strtotime(gmdate('Y-m-01').' +'.$offset.' months'));
}

function ptName(int $offset): string
{
    return 'sync_runs_y'.gmdate('Y', strtotime(ptMonth($offset))).'m'.gmdate('m', strtotime(ptMonth($offset)));
}

it('partitions sync_runs by range on started_at, with a default partition and the current and next month', function () {
    $strategy = Cluster::rows(Cluster::superuser(), "select partstrat, pg_get_partkeydef('sync_runs'::regclass) as key from pg_partitioned_table where partrelid = 'sync_runs'::regclass")[0];
    $partitions = ptPartitions();

    expect($strategy)->toMatchArray(['partstrat' => 'r', 'key' => 'RANGE (started_at)'])
        ->and($partitions)->toContain('sync_runs_default', ptName(0), ptName(1))
        ->and(Cluster::rows(Cluster::superuser(), "select relkind from pg_class where oid = 'sync_runs'::regclass")[0]['relkind'])->toBe('p');

    // The primary key holds the partition key, as PostgreSQL requires.
    $key = Cluster::rows(Cluster::superuser(), "select a.attname from pg_index i join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any (i.indkey) where i.indrelid = 'sync_runs'::regclass and i.indisprimary order by a.attname");
    expect(array_column($key, 'attname'))->toBe(['id', 'started_at']);
});

it('holds ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy on the parent and on every partition, owned by migrator', function () {
    $tables = ['sync_runs', ...ptPartitions()];

    foreach ($tables as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator'], $table);

        $column = Cluster::rows(Cluster::superuser(), "select is_nullable from information_schema.columns where table_name = ? and column_name = 'workspace_id'", [$table])[0];
        expect($column['is_nullable'])->toBe('NO', "{$table}.workspace_id must be NOT NULL");

        $policies = array_column(Cluster::rows(Cluster::superuser(), "select qual from pg_policies where tablename = ? and cmd in ('ALL', 'SELECT') and roles = '{public}'", [$table]), 'qual');
        expect(str_contains(implode(' ', $policies), "current_setting('app.workspace_id'::text, true)"))->toBeTrue("{$table}: workspace policy");
    }
});

it('gives app SELECT, INSERT and UPDATE and nothing else on every partition, and keeps system and operator out', function () {
    foreach (['sync_runs', ...ptPartitions()] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            $expected = in_array($privilege, ['SELECT', 'INSERT', 'UPDATE'], true);

            expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['app', $table, $privilege])[0]['p'])->toBe($expected, "app {$privilege} on {$table}")
                ->and(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['system', $table, $privilege])[0]['p'])->toBeFalse("system {$privilege} on {$table}")
                ->and(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['operator', $table, $privilege])[0]['p'])->toBeFalse("operator {$privilege} on {$table}");
        }

        expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['maintenance', $table, 'DELETE'])[0]['p'])->toBeTrue("maintenance DELETE on {$table}");
    }
});

it('returns nothing from any partition with no context, and never another Workspace\'s rows, even when the partition is queried directly', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rows = [
        'current' => [Cluster::seedSyncRun($a), Cluster::seedSyncRun($b)],
        'next' => [Cluster::seedSyncRun($a, "date_trunc('month', now()) + interval '1 month' + interval '1 day'"), Cluster::seedSyncRun($b, "date_trunc('month', now()) + interval '1 month' + interval '1 day'")],
        'default' => [Cluster::seedSyncRun($a, "'2001-01-01'"), Cluster::seedSyncRun($b, "'2001-01-01'")],
    ];
    $partition = ['current' => ptName(0), 'next' => ptName(1), 'default' => 'sync_runs_default'];

    foreach ($rows as $which => [$rowA, $rowB]) {
        $table = $partition[$which];

        // The row sits in the partition the table says it does.
        expect(array_column(Cluster::rows(Cluster::superuser(), "select id from {$table}"), 'id'))->toContain($rowA, $rowB);

        foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
            expect(Cluster::rows($app, "select * from {$table}"))->toBe([], "{$table} returned rows with no context");
        }

        $asA = Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "select id, workspace_id from {$table}"));
        $byId = Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "select id from {$table} where id = ?", [$rowB]));

        expect(array_column($asA, 'id'))->toBe([$rowA], $table)
            ->and(array_column($asA, 'workspace_id'))->each->toBe($a)
            ->and($byId)->toBe([], "{$table}: A read B's row by ID");
    }

    // And through the parent, which is the only way the application reads.
    $parent = Cluster::inWorkspace(Cluster::pooledApp(), $b, fn ($pdo) => Cluster::rows($pdo, 'select id from sync_runs order by started_at'));
    expect(array_column($parent, 'id'))->toEqualCanonicalizing(array_column($rows, 1));
});

it('refuses to write a run for another Workspace, through the parent or into a partition directly', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $insert = fn (string $table) => fn ($pdo) => Cluster::rows($pdo, "insert into {$table} (id, workspace_id, kind, url_template, status, started_at) values (?, ?, 'connection_test', 'https://api.example.com', 'failed', now())", [(string) Str::uuid7(), $b]);

    foreach (['sync_runs', ptName(0), 'sync_runs_default'] as $table) {
        expect(fn () => Cluster::inWorkspace(Cluster::pooledApp(), $a, $insert($table)))->toThrow(PDOException::class);
    }

    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_runs')[0]['n'])->toBe(0);
});

it('routes a run to the partition of its month: this month, next month, anything else to the default', function () {
    $a = Cluster::workspace('A');
    $now = Cluster::seedSyncRun($a);
    $next = Cluster::seedSyncRun($a, "date_trunc('month', now()) + interval '1 month'");
    $old = Cluster::seedSyncRun($a, "'2001-02-03 04:05:06+00'");
    $where = fn (string $id): string => Cluster::rows(Cluster::superuser(), 'select tableoid::regclass::text as t from sync_runs where id = ?', [$id])[0]['t'];

    expect($where($now))->toBe(ptName(0))
        ->and($where($next))->toBe(ptName(1))
        ->and($where($old))->toBe('sync_runs_default');
});

it('ensures the partitions idempotently: a second call creates nothing, and a new month has the same security', function () {
    $app = Cluster::pooledApp();
    $first = (int) Cluster::rows($app, 'select connector_ensure_sync_run_partitions(3) as n')[0]['n'];
    $second = (int) Cluster::rows($app, 'select connector_ensure_sync_run_partitions(3) as n')[0]['n'];

    expect($second)->toBe(0)
        ->and($first)->toBeGreaterThanOrEqual(0)
        ->and(ptPartitions())->toContain(ptName(0), ptName(1), ptName(2), ptName(3));

    $table = ptName(3);
    $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
    expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator'])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from pg_policies where tablename = ?', [$table])[0]['n'])->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), "select has_table_privilege('app', ?::regclass, 'SELECT') as p", [$table])[0]['p'])->toBeTrue();

    // A row of that month lands in it and stays isolated.
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $mine = Cluster::seedSyncRun($a, "date_trunc('month', now()) + interval '3 month' + interval '2 days'");
    Cluster::seedSyncRun($b, "date_trunc('month', now()) + interval '3 month' + interval '2 days'");

    expect(array_column(Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "select id from {$table}")), 'id'))->toBe([$mine]);
});

it('runs the ensure step through the command, and refuses a bad --months', function () {
    $this->artisan('dashflow:partitions:ensure', ['--months' => '1'])->assertSuccessful();
    $this->artisan('dashflow:partitions:ensure', ['--months' => '1'])->expectsOutputToContain('already exists')->assertSuccessful();
    $this->artisan('dashflow:partitions:ensure', ['--months' => '25'])->assertExitCode(2);
    $this->artisan('dashflow:partitions:ensure', ['--months' => 'x'])->assertExitCode(2);
    $this->artisan('dashflow:partitions:ensure', ['--months' => '-1'])->assertExitCode(2);

    expect(fn () => Cluster::rows(Cluster::pooledApp(), 'select connector_ensure_sync_run_partitions(99)'))->toThrow(PDOException::class, 'months_ahead');
});

it('skips a month whose rows already sit in the default partition, with a warning, and loses no row', function () {
    $a = Cluster::workspace('A');
    $id = Cluster::seedSyncRun($a, "date_trunc('month', now()) + interval '9 month' + interval '1 day'");
    $table = ptName(9);

    // Months up to the one that cannot be created still are; this one stays with the default partition.
    $created = (int) Cluster::rows(Cluster::pooledApp(), 'select connector_ensure_sync_run_partitions(9) as n')[0]['n'];

    // The command says so and fails, rather than report success.
    $this->artisan('dashflow:partitions:ensure', ['--months' => '9'])->expectsOutputToContain('default partition already holds rows')->assertExitCode(1);

    expect($created)->toBeGreaterThanOrEqual(0)
        ->and(ptPartitions())->not->toContain($table)
        ->and(Cluster::rows(Cluster::superuser(), 'select tableoid::regclass::text as t from sync_runs where id = ?', [$id])[0]['t'])->toBe('sync_runs_default');
});

it('keeps the ensure function away from system and operator', function () {
    foreach (['app' => true, 'maintenance' => true, 'system' => false, 'operator' => false] as $role => $expected) {
        expect(Cluster::rows(Cluster::superuser(), "select has_function_privilege(?, 'connector_ensure_sync_run_partitions(integer)', 'EXECUTE') as p", [$role])[0]['p'])->toBe($expected, $role);
    }
});

it('refuses a run that carries a query, userinfo or fragment in its URL template, and an unknown status or kind', function (string $column, string $value) {
    $a = Cluster::workspace('A');
    $values = ['kind' => 'connection_test', 'url_template' => 'https://api.example.com/v1', 'status' => 'failed', $column => $value];

    expect(fn () => Cluster::superuser()->prepare('insert into sync_runs (id, workspace_id, kind, url_template, status, started_at) values (?, ?, ?, ?, ?, now())')
        ->execute([(string) Str::uuid7(), $a, $values['kind'], $values['url_template'], $values['status']]))->toThrow(PDOException::class, 'violates check constraint');
})->with([
    'a query' => ['url_template', 'https://api.example.com/v1?key=CANARY'],
    'a fragment' => ['url_template', 'https://api.example.com/v1#x'],
    'userinfo' => ['url_template', 'https://user:pw@api.example.com/v1'],
    'an unknown status' => ['status', 'running'],
    'a bad kind' => ['kind', 'Connection Test'],
]);

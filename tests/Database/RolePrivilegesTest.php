<?php

use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

const APP_DELETABLE = [
    'sessions', 'password_reset_tokens', 'personal_access_tokens',
    'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
];

it('grants DELETE on every table to maintenance, and to app only on the framework\'s global tables', function () {
    foreach (Cluster::allTables() as $table) {
        $privilege = fn (string $role): bool => Cluster::rows(Cluster::superuser(), "select has_table_privilege(?, ?, 'DELETE') as p", [$role, "public.{$table}"])[0]['p'];

        // The owner holds every privilege on its own tables; it is not compared.
        expect($privilege('maintenance'))->toBeTrue("maintenance DELETE on {$table}")
            ->and($privilege('app'))->toBe(in_array($table, APP_DELETABLE, true), "app DELETE on {$table}")
            ->and($privilege('system'))->toBeFalse("system DELETE on {$table}")
            ->and($privilege('operator'))->toBeFalse("operator DELETE on {$table}");
    }

    foreach (Cluster::tenantTables() as $table) {
        expect(in_array($table, APP_DELETABLE, true))->toBeFalse("{$table} is a tenant table and must not be app-deletable");
    }
});

it('lets app do the framework\'s own deletes: a logout-style session delete works', function () {
    foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
        $id = 'sess-'.bin2hex(random_bytes(4));
        Cluster::superuser()->prepare('insert into sessions (id, payload, last_activity) values (?, ?, ?)')->execute([$id, 'x', time()]);

        $stmt = $app->prepare('delete from sessions where id = ?');
        $stmt->execute([$id]);

        expect($stmt->rowCount())->toBe(1)
            ->and(Cluster::rows(Cluster::superuser(), 'select id from sessions where id = ?', [$id]))->toBe([]);
    }
});

it('gives app SELECT, INSERT and UPDATE on tenant tables and nothing else', function () {
    foreach (Cluster::tenantTables() as $table) {
        $privileges = [];
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            $privileges[$privilege] = Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', "public.{$table}", $privilege])[0]['p'];
        }

        // Append-only and relay-owned tables: app never updates them.
        $updatable = ! in_array($table, ['audit_events', 'outbox_events', 'outbox_consumptions'], true);

        expect($privileges)->toBe([
            'SELECT' => true, 'INSERT' => true, 'UPDATE' => $updatable,
            'DELETE' => false, 'TRUNCATE' => false, 'REFERENCES' => false, 'TRIGGER' => false,
        ], "app privileges on {$table}");
    }
});

it('refuses DELETE to app, even inside its own Workspace', function () {
    $a = Cluster::workspace('A');
    Cluster::seedTenantRow('workspace_memberships', $a);

    foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
        expect(fn () => Cluster::inWorkspace($app, $a, fn ($pdo) => $pdo->exec('delete from workspace_memberships')))
            ->toThrow(PDOException::class, 'permission denied');
    }

    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(1);
});

it('lets maintenance delete, still under row-level security', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedTenantRow('workspace_memberships', $a);
    Cluster::seedTenantRow('workspace_memberships', $b);

    $maintenance = Cluster::maintenance();

    // No context: it deletes nothing.
    expect($maintenance->exec('delete from workspace_memberships'))->toBe(0);

    // With a context: only that Workspace's rows.
    expect(Cluster::inWorkspace($maintenance, $a, fn ($pdo) => $pdo->exec('delete from workspace_memberships')))->toBe(1);
    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspace_memberships')[0]['n'])->toBe(1);
});

it('does not let app bypass or change row-level security', function () {
    $app = Cluster::directApp();

    foreach ([
        'alter table workspace_memberships disable row level security',
        'alter table workspace_memberships no force row level security',
        'drop policy workspace_isolation on workspace_memberships',
        'alter role app bypassrls',
        'set role migrator',
        'set role postgres',
        'create table app_owned (id int)',
    ] as $statement) {
        expect(fn () => $app->exec($statement))->toThrow(PDOException::class);
    }

    expect(Cluster::rows($app, "select rolbypassrls from pg_roles where rolname = 'app'")[0]['rolbypassrls'])->toBeFalse();
});

it('gives system no table-level privileges and operator INSERT on workspaces, invitations and operator_audit only', function () {
    $operatorInsert = ['workspaces', 'invitations', 'operator_audit'];

    foreach (['system', 'operator'] as $role) {
        foreach (Cluster::allTables() as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                $expected = $role === 'operator' && $privilege === 'INSERT' && in_array($table, $operatorInsert, true);

                expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', [$role, "public.{$table}", $privilege])[0]['p'])
                    ->toBe($expected, "{$role} {$privilege} on {$table}");
            }
        }
    }
});

it('gives app SELECT and UPDATE of used_at and updated_at only on invitations, and nothing on operator_audit or workspaces', function () {
    $has = fn (string $table, string $privilege): bool => Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', "public.{$table}", $privilege])[0]['p'];
    $column = fn (string $name): bool => Cluster::rows(Cluster::superuser(), "select has_column_privilege('app', 'public.invitations', ?, 'UPDATE') as p", [$name])[0]['p'];

    expect($has('invitations', 'SELECT'))->toBeTrue()
        ->and($has('invitations', 'UPDATE'))->toBeFalse()
        ->and($has('invitations', 'INSERT'))->toBeFalse()
        ->and($has('invitations', 'DELETE'))->toBeFalse()
        ->and($column('used_at'))->toBeTrue()->and($column('updated_at'))->toBeTrue();

    foreach (['token_hash', 'expires_at', 'email', 'workspace_id', 'role'] as $name) {
        expect($column($name))->toBeFalse("app UPDATE on invitations.{$name}");
    }

    foreach (['operator_audit', 'workspaces'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect($has($table, $privilege))->toBeFalse("app {$privilege} on {$table}");
        }
    }
});

it('denies operator UPDATE and DELETE on operator_audit and invitations, SELECT on invitations, and any column of workspaces', function () {
    $operator = Cluster::operator();

    foreach ([
        'update operator_audit set actor = \'x\'',
        'delete from operator_audit',
        'update invitations set used_at = now()',
        'update invitations set token_hash = \'x\', expires_at = now()',
        'delete from invitations',
        'select * from invitations',
        'select name from workspaces',
        'select label from workspaces',
        'update workspaces set label = \'x\'',
        'delete from workspaces',
    ] as $statement) {
        expect(fn () => $operator->query($statement))->toThrow(PDOException::class, 'permission denied');
    }
});

it('lets app update used_at on an invitation but not its hash, expiry, email or Workspace', function () {
    $w = Cluster::workspace('A');
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare("insert into invitations (id, workspace_id, email, token_hash, role, expires_at, created_at, updated_at) values (?, ?, 'a@example.test', ?, 'admin', now() + interval '1 day', now(), now())")
        ->execute([$id, $w, str_repeat('a', 64)]);

    foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
        expect($app->exec("update invitations set used_at = now(), updated_at = now() where id = '{$id}'"))->toBe(1);

        foreach (['token_hash' => "'x'", 'expires_at' => 'now()', 'email' => "'b@example.test'", 'workspace_id' => "'{$w}'"] as $column => $value) {
            expect(fn () => $app->exec("update invitations set {$column} = {$value} where id = '{$id}'"))->toThrow(PDOException::class, 'permission denied');
        }
    }
});

it('refuses app an INSERT into workspaces, directly and through the pool', function () {
    foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
        expect(fn () => $app->exec("insert into workspaces (id, name, status, created_at, updated_at) values ('".(string) Str::uuid7()."', 'X', 'active', now(), now())"))
            ->toThrow(PDOException::class, 'permission denied');
    }

    expect((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from workspaces')[0]['n'])->toBe(0);
});

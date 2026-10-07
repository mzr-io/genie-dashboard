<?php

use App\Platform\Tenancy\WorkspaceTransaction;
use Tests\Database\Support\Cluster;

it('shares one PostgreSQL server connection between two PgBouncer clients', function () {
    $one = Cluster::pooledApp();
    $two = Cluster::pooledApp();

    expect(Cluster::rows($one, 'select pg_backend_pid() as pid')[0]['pid'])
        ->toBe(Cluster::rows($two, 'select pg_backend_pid() as pid')[0]['pid']);
});

it('keeps interleaved requests for Workspaces A and B apart on one server connection', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    $rowB = Cluster::seedTenantRow('workspace_memberships', $b);

    $clientA = Cluster::pooledApp();
    $clientB = Cluster::pooledApp();

    $pidA = Cluster::rows($clientA, 'select pg_backend_pid() as pid')[0]['pid'];

    // A sets its context and reads.
    $clientA->beginTransaction();
    Cluster::rows($clientA, "select set_config('app.workspace_id', ?, true)", [$a]);
    expect(array_column(Cluster::rows($clientA, 'select id from workspace_memberships'), 'id'))->toBe([$rowA]);
    $clientA->commit();

    // B gets the very same server connection: A's context must not have survived the transaction.
    $clientB->beginTransaction();
    expect(Cluster::rows($clientB, 'select pg_backend_pid() as pid')[0]['pid'])->toBe($pidA)
        ->and(Cluster::rows($clientB, "select nullif(current_setting('app.workspace_id', true), '') as ctx")[0]['ctx'])->toBeNull()
        ->and(Cluster::rows($clientB, 'select id from workspace_memberships'))->toBe([]);
    Cluster::rows($clientB, "select set_config('app.workspace_id', ?, true)", [$b]);
    expect(array_column(Cluster::rows($clientB, 'select id from workspace_memberships'), 'id'))->toBe([$rowB]);
    $clientB->commit();

    // A, outside a transaction again, sees nothing.
    expect(Cluster::rows($clientA, 'select id from workspace_memberships'))->toBe([]);

    // Alternate several times.
    foreach (range(1, 6) as $i) {
        [$ws, $row, $client] = $i % 2 === 0 ? [$a, $rowA, $clientA] : [$b, $rowB, $clientB];
        $seen = Cluster::inWorkspace($client, $ws, fn ($pdo) => array_column(Cluster::rows($pdo, 'select id from workspace_memberships'), 'id'));
        expect($seen)->toBe([$row]);
    }
});

it('keeps WorkspaceTransaction contexts apart through the framework and PgBouncer', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    $rowB = Cluster::seedTenantRow('workspace_memberships', $b);

    $transaction = app(WorkspaceTransaction::class);
    $read = fn () => DB::table('workspace_memberships')->pluck('id')->all();

    expect($transaction->run($a, $read))->toBe([$rowA])
        ->and($read())->toBe([])
        ->and($transaction->run($b, $read))->toBe([$rowB])
        ->and($read())->toBe([]);
});

it('keeps interleaved A/B requests apart with emulated prepares (DB_PGBOUNCER=true) through PgBouncer', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    $rowB = Cluster::seedTenantRow('workspace_memberships', $b);

    $emulated = [PDO::ATTR_EMULATE_PREPARES => true];
    $clientA = Cluster::pooledApp($emulated);
    $clientB = Cluster::pooledApp($emulated);

    expect($clientA->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeTrue()
        ->and(Cluster::rows($clientA, 'select pg_backend_pid() as pid')[0]['pid'])
        ->toBe(Cluster::rows($clientB, 'select pg_backend_pid() as pid')[0]['pid']);

    foreach (range(1, 6) as $i) {
        [$ws, $row, $client, $otherRow] = $i % 2 === 0 ? [$a, $rowA, $clientA, $rowB] : [$b, $rowB, $clientB, $rowA];

        // Without a context, nothing; with one, only its own row; afterwards, nothing again.
        expect(Cluster::rows($client, 'select id from workspace_memberships'))->toBe([]);
        $seen = Cluster::inWorkspace($client, $ws, fn ($pdo) => array_column(Cluster::rows($pdo, 'select id from workspace_memberships where id <> ? or id = ?', [$otherRow, $otherRow]), 'id'));
        expect($seen)->toBe([$row])
            ->and(Cluster::rows($client, 'select id from workspace_memberships'))->toBe([]);
    }
});

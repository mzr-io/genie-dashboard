<?php

namespace Tests\Database\Support;

/**
 * The per-table cross-tenant leak checks (Story 1.10), shared by the leak tests in RowLevelSecurityTest and
 * by the guard in TenantLeakCoverageTest. Every table comes from the live schema through tables(); a tenant
 * table without a seeder in Cluster::seedTenantRow() fails here, by name.
 */
final class LeakChecks
{
    /** @return list<string> */
    public static function tables(): array
    {
        return Cluster::tenantTables();
    }

    public static function noContext(string $table): void
    {
        $a = Cluster::workspace('A');

        expect(Cluster::seedTenantRow($table, $a))->not->toBeNull("add a seeder for tenant table {$table} to Cluster::seedTenantRow");

        $total = Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
        expect((int) $total)->toBeGreaterThan(0);

        foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
            expect(Cluster::rows($app, "select * from {$table}"))->toBe([], "{$table} returned rows with no context");
        }
    }

    public static function crossWorkspace(string $table): void
    {
        $a = Cluster::workspace('A');
        $b = Cluster::workspace('B');

        $rowA = Cluster::seedTenantRow($table, $a);
        $rowB = Cluster::seedTenantRow($table, $b);
        expect($rowA)->not->toBeNull("add a seeder for tenant table {$table} to Cluster::seedTenantRow");

        $asA = Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "select id, workspace_id from {$table}"));
        $asB = Cluster::inWorkspace(Cluster::pooledApp(), $b, fn ($pdo) => Cluster::rows($pdo, "select id, workspace_id from {$table}"));

        // A table's seeder may also seed a parent row of another tenant table (a permission needs a membership),
        // so assert membership of the result, not equality.
        expect(array_column($asA, 'id'))->toContain($rowA)->not->toContain($rowB)
            ->and(array_column($asA, 'workspace_id'))->each->toBe($a)
            ->and(array_column($asB, 'id'))->toContain($rowB)->not->toContain($rowA)
            ->and(array_column($asB, 'workspace_id'))->each->toBe($b);

        // Reaching for the other Workspace's row by ID returns nothing.
        $byId = Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, "select id from {$table} where id = ?", [$rowB]));
        expect($byId)->toBe([], "{$table}: Workspace A read B's row by ID");
    }
}

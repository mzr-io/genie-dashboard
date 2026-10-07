<?php

use Tests\Database\Support\Cluster;
use Tests\Database\Support\LeakChecks;

// Story 1.25: a tenant table (every table with `workspace_id`, read from the live schema, minus the
// listed global tables) must have a leak seeder in Cluster::seedTenantRow() and pass both leak checks.
// The leak tests in RowLevelSecurityTest iterate LeakChecks::tables(), which is the live table list, and
// this guard runs the same checks for every live table: a new table without a seeder fails naming it.

it('feeds the leak tests from the live tenant table list', function () {
    expect(LeakChecks::tables())->toBe(Cluster::tenantTables())
        ->and(LeakChecks::tables())->toContain('workspace_memberships', 'audit_events');
});

it('has a leak seeder that returns a row for every tenant table', function () {
    foreach (Cluster::tenantTables() as $table) {
        expect(Cluster::seedTenantRow($table, Cluster::workspace('Guard '.$table)))
            ->not->toBeNull("tenant table {$table} has no leak seeder in Cluster::seedTenantRow()");
    }
});

it('passes the context-free and cross-Workspace leak checks for every tenant table', function () {
    foreach (Cluster::tenantTables() as $table) {
        LeakChecks::noContext($table);
        LeakChecks::crossWorkspace($table);
    }
});

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Operator private-range grants (Story 2.2), owned by Connector.
|
|  egress_grants   one row per grant of a private network (`cidr`, canonical text) to one Workspace, with the
|                  operator's `reason`, who granted it and when, and, once revoked, when and by whom. A row is never
|                  deleted: revocation sets `revoked_at` and `revoked_by`. At most one active grant per Workspace and
|                  CIDR (a partial unique index); a revoked range can be granted again.
|
|  A tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy, created as
|  `migrator`. Only the operator manages grants: `app` (the guard) keeps SELECT and loses the default INSERT and
|  UPDATE; `operator` gets SELECT, INSERT and UPDATE of `revoked_at` and `revoked_by` only. No role but `maintenance`
|  (retention) can delete.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egress_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('cidr', 49);
            $table->text('reason');
            $table->string('granted_by', 64);
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_by', 64)->nullable();

            $table->index('workspace_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator', 'operator')"), 'rolname');
        if (array_diff(['app', 'migrator', 'operator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app, migrator and operator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE egress_grants ADD CONSTRAINT egress_grants_cidr_check
                CHECK (cidr ~ '^[0-9a-f:.]{2,45}/[0-9]{1,3}$' AND cidr = lower(cidr));
            ALTER TABLE egress_grants ADD CONSTRAINT egress_grants_reason_check
                CHECK (char_length(reason) BETWEEN 1 AND 500);
            ALTER TABLE egress_grants ADD CONSTRAINT egress_grants_revocation_check
                CHECK ((revoked_at IS NULL) = (revoked_by IS NULL));
            CREATE UNIQUE INDEX egress_grants_active_unique ON egress_grants (workspace_id, cidr) WHERE revoked_at IS NULL;

            ALTER TABLE egress_grants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE egress_grants FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON egress_grants
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            REVOKE INSERT, UPDATE ON egress_grants FROM app;
            GRANT SELECT, INSERT ON egress_grants TO operator;
            GRANT UPDATE (revoked_at, revoked_by) ON egress_grants TO operator;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('egress_grants');
    }
};

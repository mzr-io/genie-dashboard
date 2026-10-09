<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Data Source health (Story 2.18).
|
|  data_sources.health_path   Connector: the optional relative path a probe adds to the Base URL (starts with `/`, no query, fragment or `..`,
|                             at most 255 characters; the application validates it like an Endpoint path).
|  data_source_health         Ingestion: one read model row per Data Source (no foreign key across modules), fed by outbox events, probe
|                             results, final runs and breaker changes. `status` is `checking` until evidence exists; `status_seq` rises with
|                             every change. `last_probe_*` is the latest save-time or periodic probe; `next_probe_at` is when the periodic
|                             tick may probe it again (null: never). A tenant table under row-level security; role `system` (the due-probe
|                             tick) has column-limited SELECT and UPDATE (next_probe_at) under policies `TO system`, never BYPASSRLS.
|
| sync_runs.kind is a free `[a-z][a-z0-9_]*` token, so the probe's `health_probe` needs no change there.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('health_path', 255)->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'system')"), 'rolname');
        if (array_diff(['app', 'system'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and system. Create them with docker/postgres/initdb.sh before migrating.');
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<SQL
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_health_path_check CHECK (
                health_path IS NULL OR (health_path ~ '^/[^?#\\\\ ]*\$' AND health_path !~ '(^|/)\.\.?(/|\$)'));

            CREATE TABLE data_source_health (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                data_source_id uuid NOT NULL,
                status varchar(12) NOT NULL DEFAULT 'checking',
                status_since timestamptz NOT NULL DEFAULT now(),
                status_seq bigint NOT NULL DEFAULT 0,
                last_probe_at timestamptz,
                last_probe_ok boolean,
                last_probe_success_at timestamptz,
                next_probe_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                CONSTRAINT data_source_health_status_check CHECK (status IN ('checking', 'healthy', 'degraded', 'unreachable')),
                CONSTRAINT data_source_health_seq_check CHECK (status_seq >= 0)
            );

            CREATE UNIQUE INDEX data_source_health_source_unique ON data_source_health (workspace_id, data_source_id);
            CREATE INDEX data_source_health_probe_index ON data_source_health (next_probe_at) WHERE next_probe_at IS NOT NULL;

            ALTER TABLE data_source_health ENABLE ROW LEVEL SECURITY;
            ALTER TABLE data_source_health FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON data_source_health
                USING ({$workspace}) WITH CHECK ({$workspace});

            -- The due-probe tick (role `system`): which sources are due, and the one column it moves.
            GRANT SELECT (id, workspace_id, data_source_id, next_probe_at) ON data_source_health TO system;
            GRANT UPDATE (next_probe_at) ON data_source_health TO system;
            CREATE POLICY probe_select ON data_source_health FOR SELECT TO system
                USING (next_probe_at IS NOT NULL);
            CREATE POLICY probe_update ON data_source_health FOR UPDATE TO system
                USING (next_probe_at IS NOT NULL AND next_probe_at <= now())
                WITH CHECK (next_probe_at IS NOT NULL);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TABLE IF EXISTS data_source_health CASCADE;
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_health_path_check;
                SQL);
        }

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('health_path');
        });
    }
};

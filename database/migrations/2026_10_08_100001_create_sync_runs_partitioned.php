<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| sync_runs (Story 2.5), owned by Connector for now (Ingestion does not exist yet and Connector writes it): one row for
| every outbound attempt, a connection test today and the scheduled fetches of Story 2.14 later. It is the first
| partitioned table: PARTITION BY RANGE (started_at), one partition per calendar month (UTC) named
| `sync_runs_yYYYYmMM` and a DEFAULT partition, so a row is never refused for want of a partition.
|
|  columns   kind, the sanitised URL template (never a query, userinfo or fragment), status, HTTP status, latency (ms),
|            bytes, error code, request_id and the Data Source id. Never a body or a secret. The primary key holds
|            the partition key (id, started_at), as PostgreSQL requires.
|
|  A tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy on the parent AND
|  on every partition (a partition queried directly is checked by its own policy). Role `app` has SELECT, INSERT and
|  UPDATE through the default privileges, which also reach every partition because `migrator` creates them.
|
|  Partition upkeep is `connector_ensure_sync_run_partitions(months_ahead)`: a SECURITY DEFINER function owned by
|  `migrator`, idempotent (a month that has a partition is skipped), that creates the current month and the next
|  `months_ahead` months with the same security as the parent. This migration calls it for the current and the next
|  month; `php artisan dashflow:partitions:ensure` calls it again, and Epic 9's scheduler only has to run that command
|  (monthly at the latest). A month whose rows already sit in the DEFAULT partition cannot be created: the function
|  reports it with a warning and skips it, never loses a row.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'maintenance', 'migrator')"), 'rolname');
        if (array_diff(['app', 'maintenance', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app, maintenance and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE sync_runs (
                id uuid NOT NULL,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                data_source_id uuid,
                kind varchar(32) NOT NULL,
                url_template varchar(2048) NOT NULL,
                status varchar(16) NOT NULL,
                http_status smallint,
                latency_ms integer,
                bytes bigint,
                error_code varchar(48),
                request_id varchar(64),
                started_at timestamptz NOT NULL,
                created_at timestamptz,
                updated_at timestamptz,
                PRIMARY KEY (id, started_at),
                CONSTRAINT sync_runs_status_check CHECK (status IN ('succeeded', 'failed')),
                CONSTRAINT sync_runs_kind_check CHECK (kind ~ '^[a-z][a-z0-9_]{0,31}$'),
                CONSTRAINT sync_runs_url_template_check CHECK (url_template !~ '[?#]' AND url_template !~ '^[a-z][a-z0-9+.-]*://[^/]*@')
            ) PARTITION BY RANGE (started_at);

            CREATE INDEX sync_runs_workspace_started_index ON sync_runs (workspace_id, started_at DESC);
            CREATE INDEX sync_runs_data_source_index ON sync_runs (workspace_id, data_source_id, started_at DESC);

            ALTER TABLE sync_runs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE sync_runs FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON sync_runs
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            CREATE TABLE sync_runs_default PARTITION OF sync_runs DEFAULT;
            ALTER TABLE sync_runs_default ENABLE ROW LEVEL SECURITY;
            ALTER TABLE sync_runs_default FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON sync_runs_default
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            DROP FUNCTION IF EXISTS connector_ensure_sync_run_partitions(integer);

            CREATE FUNCTION connector_ensure_sync_run_partitions(p_months_ahead integer DEFAULT 1)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_first date := date_trunc('month', now() AT TIME ZONE 'UTC')::date;
                v_from date;
                v_to date;
                v_name text;
                v_created integer := 0;
                i integer;
            BEGIN
                IF p_months_ahead IS NULL OR p_months_ahead < 0 OR p_months_ahead > 24 THEN
                    RAISE EXCEPTION 'months_ahead must be between 0 and 24' USING ERRCODE = '22023';
                END IF;

                FOR i IN 0..p_months_ahead LOOP
                    v_from := (v_first + make_interval(months => i))::date;
                    v_to := (v_first + make_interval(months => i + 1))::date;
                    v_name := 'sync_runs_y' || to_char(v_from, 'YYYY') || 'm' || to_char(v_from, 'MM');

                    IF to_regclass(format('public.%I', v_name)) IS NOT NULL THEN
                        CONTINUE;
                    END IF;

                    BEGIN
                        EXECUTE format(
                            'CREATE TABLE public.%I PARTITION OF public.sync_runs FOR VALUES FROM (%L) TO (%L)',
                            v_name, v_from::text || ' 00:00:00+00', v_to::text || ' 00:00:00+00'
                        );
                        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', v_name);
                        EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', v_name);
                        EXECUTE format(
                            'CREATE POLICY workspace_isolation ON public.%I USING (workspace_id = nullif(current_setting(''app.workspace_id'', true), '''')::uuid) WITH CHECK (workspace_id = nullif(current_setting(''app.workspace_id'', true), '''')::uuid)',
                            v_name
                        );
                        v_created := v_created + 1;
                    EXCEPTION WHEN check_violation THEN
                        -- Rows for this month already sit in the DEFAULT partition: nothing is moved or lost.
                        RAISE WARNING 'sync_runs partition % was not created: the default partition holds rows for it', v_name;
                    END;
                END LOOP;

                RETURN v_created;
            END
            $fn$;

            REVOKE ALL ON FUNCTION connector_ensure_sync_run_partitions(integer) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION connector_ensure_sync_run_partitions(integer) TO app, maintenance;

            SELECT connector_ensure_sync_run_partitions(1);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS connector_ensure_sync_run_partitions(integer)');
        DB::unprepared('DROP TABLE IF EXISTS sync_runs CASCADE');
    }
};

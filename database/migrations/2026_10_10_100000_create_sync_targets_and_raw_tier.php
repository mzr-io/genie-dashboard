<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Scheduled fetch and the last good response (Story 2.14).
|
|  endpoint_revisions.test_values  Connector: the Admin's test values for the date-range and period parameters of the revision
|                                  (name => ISO date, `header:{name}` for a header). Set on insert; the immutability trigger stays.
|
|  sync_targets   Ingestion: one representative target per shared-context Endpoint revision of a Data Source revision, keyed by
|                 its `fk1:` fetch key (unique per Workspace). It carries the dispatch fence (`dispatch_seq`, `applied_seq`), the
|                 payload pointer (`current_payload_id`, `payload_seq`) and the schedule (`next_due_at`, `retired_at`). The
|                 ids of Connector's rows (data source, endpoint, revision) and of the raw payload are plain columns: no foreign
|                 key crosses a module. Role `system` (the dispatcher) has column-limited SELECT and UPDATE (dispatch_seq,
|                 next_due_at) under policies `TO system`, and never BYPASSRLS.
|
|  raw_bodies        RawStore: the exact response bytes (bytea, lz4), content-addressed per Workspace and target by the sha256 of
|                    the bytes (a CHECK keeps the hash honest). Never jsonb.
|  raw_observations  RawStore: one immutable row per accepted response, partitioned by month on `observed_at` like `sync_runs`
|                    (DEFAULT partition plus `rawstore_ensure_raw_observation_partitions`).
|
|  Role `app` has SELECT and INSERT only on both raw tables and every partition; a trigger fails an UPDATE for any role.
|
|  sync_runs gains `sync_target_id`, `dispatch_seq` and `parameter_names` and the status `superseded`.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        Schema::table('endpoint_revisions', function ($table) {
            $table->jsonb('test_values')->default('{}');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'maintenance', 'migrator', 'system')"), 'rolname');
        if (array_diff(['app', 'maintenance', 'migrator', 'system'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app, maintenance, migrator and system. Create them with docker/postgres/initdb.sh before migrating.');
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<SQL
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_test_values_check
                CHECK (jsonb_typeof(test_values) = 'object');

            CREATE TABLE sync_targets (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                fetch_key varchar(80) NOT NULL,
                data_source_id uuid NOT NULL,
                endpoint_id uuid NOT NULL,
                endpoint_revision_id uuid NOT NULL,
                data_source_revision integer NOT NULL,
                params jsonb NOT NULL DEFAULT '{}',
                sync_group_id uuid NOT NULL,
                refresh_interval_seconds integer,
                etag text,
                last_modified text,
                content_hash varchar(64),
                current_payload_id uuid,
                payload_seq bigint NOT NULL DEFAULT 0,
                dispatch_seq bigint NOT NULL DEFAULT 0,
                applied_seq bigint NOT NULL DEFAULT 0,
                last_success_at timestamptz,
                last_checked_at timestamptz,
                consecutive_failures integer NOT NULL DEFAULT 0,
                next_due_at timestamptz,
                retired_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                CONSTRAINT sync_targets_fetch_key_check CHECK (fetch_key ~ '^fk1:[0-9a-f]{64}\$'),
                CONSTRAINT sync_targets_params_check CHECK (jsonb_typeof(params) = 'object'),
                CONSTRAINT sync_targets_interval_check CHECK (refresh_interval_seconds IS NULL OR refresh_interval_seconds >= 1),
                CONSTRAINT sync_targets_seq_check CHECK (payload_seq >= 0 AND dispatch_seq >= 0 AND applied_seq >= 0 AND applied_seq <= dispatch_seq),
                CONSTRAINT sync_targets_failures_check CHECK (consecutive_failures >= 0)
            );

            CREATE UNIQUE INDEX sync_targets_fetch_key_unique ON sync_targets (workspace_id, fetch_key);
            CREATE INDEX sync_targets_endpoint_index ON sync_targets (workspace_id, endpoint_id);
            CREATE INDEX sync_targets_group_index ON sync_targets (workspace_id, sync_group_id);
            CREATE INDEX sync_targets_due_index ON sync_targets (next_due_at) WHERE retired_at IS NULL AND next_due_at IS NOT NULL;

            ALTER TABLE sync_targets ENABLE ROW LEVEL SECURITY;
            ALTER TABLE sync_targets FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON sync_targets
                USING ({$workspace}) WITH CHECK ({$workspace});

            -- The dispatcher (role `system`): the dispatch columns of the targets that can be due, and the two columns it moves.
            -- UPDATE re-checks the new row against the SELECT policy, so a dispatched row stays readable (it is still scheduled).
            GRANT SELECT (id, workspace_id, sync_group_id, refresh_interval_seconds, next_due_at, retired_at, dispatch_seq) ON sync_targets TO system;
            GRANT UPDATE (dispatch_seq, next_due_at) ON sync_targets TO system;
            CREATE POLICY dispatch_select ON sync_targets FOR SELECT TO system
                USING (retired_at IS NULL AND next_due_at IS NOT NULL);
            CREATE POLICY dispatch_update ON sync_targets FOR UPDATE TO system
                USING (retired_at IS NULL AND next_due_at IS NOT NULL AND next_due_at <= now())
                WITH CHECK (retired_at IS NULL AND next_due_at IS NOT NULL);

            CREATE TABLE raw_bodies (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                sync_target_id uuid NOT NULL,
                content_hash char(64) NOT NULL,
                size_bytes bigint NOT NULL,
                body bytea COMPRESSION lz4 NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT raw_bodies_workspace_id_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT raw_bodies_content_address_unique UNIQUE (workspace_id, sync_target_id, content_hash),
                CONSTRAINT raw_bodies_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}\$' AND content_hash = encode(sha256(body), 'hex')),
                CONSTRAINT raw_bodies_size_check CHECK (size_bytes = octet_length(body))
            );

            ALTER TABLE raw_bodies ENABLE ROW LEVEL SECURITY;
            ALTER TABLE raw_bodies FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON raw_bodies
                USING ({$workspace}) WITH CHECK ({$workspace});

            CREATE TABLE raw_observations (
                id uuid NOT NULL,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                sync_target_id uuid NOT NULL,
                payload_id uuid NOT NULL,
                seq bigint NOT NULL,
                content_hash char(64) NOT NULL,
                size_bytes bigint NOT NULL,
                dispatch_seq bigint NOT NULL,
                request_id varchar(64),
                observed_at timestamptz NOT NULL,
                PRIMARY KEY (id, observed_at),
                CONSTRAINT raw_observations_payload_fk FOREIGN KEY (workspace_id, payload_id) REFERENCES raw_bodies (workspace_id, id),
                CONSTRAINT raw_observations_seq_check CHECK (seq >= 1 AND dispatch_seq >= 1)
            ) PARTITION BY RANGE (observed_at);

            CREATE INDEX raw_observations_target_seq_index ON raw_observations (workspace_id, sync_target_id, seq);

            ALTER TABLE raw_observations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE raw_observations FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON raw_observations
                USING ({$workspace}) WITH CHECK ({$workspace});

            CREATE TABLE raw_observations_default PARTITION OF raw_observations DEFAULT;
            ALTER TABLE raw_observations_default ENABLE ROW LEVEL SECURITY;
            ALTER TABLE raw_observations_default FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON raw_observations_default
                USING ({$workspace}) WITH CHECK ({$workspace});

            -- Append-only: nobody changes a stored body or an observation (maintenance alone deletes, for retention).
            DROP FUNCTION IF EXISTS raw_tier_immutable();
            CREATE FUNCTION raw_tier_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION '% rows are immutable', TG_TABLE_NAME USING ERRCODE = '23514';
            END
            \$\$;
            CREATE TRIGGER raw_bodies_immutable BEFORE UPDATE ON raw_bodies
                FOR EACH ROW EXECUTE FUNCTION raw_tier_immutable();
            CREATE TRIGGER raw_observations_immutable BEFORE UPDATE ON raw_observations
                FOR EACH ROW EXECUTE FUNCTION raw_tier_immutable();

            REVOKE UPDATE ON raw_bodies, raw_observations, raw_observations_default FROM app;

            DROP FUNCTION IF EXISTS rawstore_ensure_raw_observation_partitions(integer);

            CREATE FUNCTION rawstore_ensure_raw_observation_partitions(p_months_ahead integer DEFAULT 1)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS \$fn\$
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
                    v_name := 'raw_observations_y' || to_char(v_from, 'YYYY') || 'm' || to_char(v_from, 'MM');

                    IF to_regclass(format('public.%I', v_name)) IS NOT NULL THEN
                        CONTINUE;
                    END IF;

                    BEGIN
                        EXECUTE format(
                            'CREATE TABLE public.%I PARTITION OF public.raw_observations FOR VALUES FROM (%L) TO (%L)',
                            v_name, v_from::text || ' 00:00:00+00', v_to::text || ' 00:00:00+00'
                        );
                        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', v_name);
                        EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', v_name);
                        EXECUTE format(
                            'CREATE POLICY workspace_isolation ON public.%I USING (workspace_id = nullif(current_setting(''app.workspace_id'', true), '''')::uuid) WITH CHECK (workspace_id = nullif(current_setting(''app.workspace_id'', true), '''')::uuid)',
                            v_name
                        );
                        EXECUTE format('REVOKE UPDATE ON public.%I FROM app', v_name);
                        v_created := v_created + 1;
                    EXCEPTION WHEN check_violation THEN
                        -- Rows for this month already sit in the DEFAULT partition: nothing is moved or lost.
                        RAISE WARNING 'raw_observations partition % was not created: the default partition holds rows for it', v_name;
                    END;
                END LOOP;

                RETURN v_created;
            END
            \$fn\$;

            REVOKE ALL ON FUNCTION rawstore_ensure_raw_observation_partitions(integer) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION rawstore_ensure_raw_observation_partitions(integer) TO app, maintenance;

            SELECT rawstore_ensure_raw_observation_partitions(1);

            -- Scheduled runs (kind scheduled_fetch): the target, the fence value, the parameter names, and the status `superseded`.
            ALTER TABLE sync_runs ADD COLUMN sync_target_id uuid;
            ALTER TABLE sync_runs ADD COLUMN dispatch_seq bigint;
            ALTER TABLE sync_runs ADD COLUMN parameter_names jsonb;
            ALTER TABLE sync_runs DROP CONSTRAINT sync_runs_status_check;
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check CHECK (status IN ('succeeded', 'failed', 'superseded'));
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_parameter_names_check
                CHECK (parameter_names IS NULL OR jsonb_typeof(parameter_names) = 'array');
            CREATE INDEX sync_runs_sync_target_index ON sync_runs (workspace_id, sync_target_id, started_at DESC);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP INDEX IF EXISTS sync_runs_sync_target_index;
                ALTER TABLE sync_runs DROP CONSTRAINT IF EXISTS sync_runs_parameter_names_check;
                ALTER TABLE sync_runs DROP CONSTRAINT IF EXISTS sync_runs_status_check;
                ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check CHECK (status IN ('succeeded', 'failed')) NOT VALID;
                ALTER TABLE sync_runs DROP COLUMN IF EXISTS parameter_names;
                ALTER TABLE sync_runs DROP COLUMN IF EXISTS dispatch_seq;
                ALTER TABLE sync_runs DROP COLUMN IF EXISTS sync_target_id;
                DROP FUNCTION IF EXISTS rawstore_ensure_raw_observation_partitions(integer);
                DROP TABLE IF EXISTS raw_observations CASCADE;
                DROP TABLE IF EXISTS raw_bodies CASCADE;
                DROP TABLE IF EXISTS sync_targets CASCADE;
                DROP FUNCTION IF EXISTS raw_tier_immutable();
                ALTER TABLE endpoint_revisions DROP CONSTRAINT IF EXISTS endpoint_revisions_test_values_check;
                SQL);
        }

        Schema::table('endpoint_revisions', function ($table) {
            $table->dropColumn('test_values');
        });
    }
};

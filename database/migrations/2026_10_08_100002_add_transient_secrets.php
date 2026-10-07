<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Transient secrets (Story 2.5), an expand-only change to Connector's `secrets` table: a connection test of a form that
| is not saved carries the credentials typed into it as rows owned by the Operation, never as a Data Source's.
|
|  data_source_id   now nullable: a transient row has none (its context owner is the Operation).
|  operation_id     the Operation that owns the row (a plain column: no foreign key across modules).
|  ephemeral        true for a transient row. A transient row has an operation_id, an expires_at and no data_source_id;
|                   a stored one has none of the first two and a data_source_id (a CHECK keeps the three together).
|  expires_at       after it the row is ignored on read and removed by `dashflow:secrets:purge-expired`.
|
|  The unique (operation_id, slot) covers transient rows only. Removal goes through SECURITY DEFINER functions owned by
|  `migrator` (role `app` cannot delete): `connector_remove_operation_secrets(operation)` removes one Operation's rows
|  for the Workspace in the context, and `connector_purge_expired_secrets()` removes every expired transient row of every
|  Workspace (it enters each Workspace's context itself, so row-level security still decides what it may delete).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secrets', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable();
            $table->boolean('ephemeral')->default(false);
            $table->timestampTz('expires_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'maintenance', 'migrator')"), 'rolname');
        if (array_diff(['app', 'maintenance', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app, maintenance and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE secrets ALTER COLUMN data_source_id DROP NOT NULL;

            ALTER TABLE secrets ADD CONSTRAINT secrets_ephemeral_check CHECK (
                ephemeral = (operation_id IS NOT NULL)
                AND ephemeral = (expires_at IS NOT NULL)
                AND ephemeral = (data_source_id IS NULL)
            );
            CREATE UNIQUE INDEX secrets_operation_slot_unique ON secrets (operation_id, slot) WHERE ephemeral;
            CREATE INDEX secrets_ephemeral_expiry_index ON secrets (expires_at) WHERE ephemeral;

            DROP FUNCTION IF EXISTS connector_remove_operation_secrets(uuid);
            DROP FUNCTION IF EXISTS connector_purge_expired_secrets();

            CREATE FUNCTION connector_remove_operation_secrets(p_operation_id uuid)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_removed integer;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                DELETE FROM public.secrets s
                WHERE s.workspace_id = v_workspace AND s.ephemeral AND s.operation_id = p_operation_id;
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed;
            END
            $fn$;

            CREATE FUNCTION connector_purge_expired_secrets()
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_previous text := current_setting('app.workspace_id', true);
                v_workspace uuid;
                v_removed integer;
                v_total integer := 0;
            BEGIN
                FOR v_workspace IN SELECT w.id FROM public.workspaces w LOOP
                    PERFORM set_config('app.workspace_id', v_workspace::text, true);

                    DELETE FROM public.secrets s
                    WHERE s.workspace_id = v_workspace AND s.ephemeral AND s.expires_at <= now();
                    GET DIAGNOSTICS v_removed = ROW_COUNT;
                    v_total := v_total + v_removed;
                END LOOP;

                PERFORM set_config('app.workspace_id', coalesce(v_previous, ''), true);

                RETURN v_total;
            END
            $fn$;

            REVOKE ALL ON FUNCTION connector_remove_operation_secrets(uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION connector_purge_expired_secrets() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION connector_remove_operation_secrets(uuid) TO app;
            GRANT EXECUTE ON FUNCTION connector_purge_expired_secrets() TO app, maintenance;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS connector_remove_operation_secrets(uuid)');
            DB::unprepared('DROP FUNCTION IF EXISTS connector_purge_expired_secrets()');
            // Transient rows have no Data Source: they must go before it is required again. The owner is bound by the
            // policy too, so the force is lifted for the delete only.
            DB::unprepared('ALTER TABLE secrets NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('DELETE FROM secrets WHERE ephemeral');
            DB::unprepared('ALTER TABLE secrets FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE secrets DROP CONSTRAINT IF EXISTS secrets_ephemeral_check');
        }

        Schema::table('secrets', function (Blueprint $table) {
            $table->dropColumn(['operation_id', 'ephemeral', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE secrets ALTER COLUMN data_source_id SET NOT NULL');
        }
    }
};

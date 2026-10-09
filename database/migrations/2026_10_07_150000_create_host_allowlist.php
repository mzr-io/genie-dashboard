<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The Workspace host allowlist (Story 2.1), owned by Connector.
|
|  host_allowlist_entries      the hosts a Workspace approves. `host` is stored lower case (IPv6 as a bracketed
|                              canonical literal), `port` always resolved (the scheme's default 443 or 80 when
|                              the Admin gave none), so the same host and port is one entry whatever the scheme.
|                              `added_by_membership_id` is a plain column: no foreign key across modules.
|  host_allowlist_versions     one row per Workspace with the list-level `revision`. Every change locks this row,
|                              compares the caller's revision and bumps it, so two Admins cannot change the list
|                              from the same stale view.
|  Both are tenant tables: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy.
|
|  Role `app` has SELECT, INSERT and UPDATE only. Removal goes through a SECURITY DEFINER function owned by
|  `migrator` and executable by `app`: it reads `current_setting('app.workspace_id', true)`, takes no Workspace
|  argument, refuses when it is unset and touches only that Workspace's rows.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_allowlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('host', 253);
            $table->string('scheme', 5);
            $table->unsignedInteger('port');
            $table->uuid('added_by_membership_id');
            $table->timestamps();

            $table->unique(['workspace_id', 'host', 'port']);
            $table->index('workspace_id');
        });

        Schema::create('host_allowlist_versions', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained('workspaces');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE host_allowlist_entries ADD CONSTRAINT host_allowlist_entries_scheme_check
                CHECK (scheme IN ('http', 'https'));
            ALTER TABLE host_allowlist_entries ADD CONSTRAINT host_allowlist_entries_port_check
                CHECK (port BETWEEN 1 AND 65535);
            ALTER TABLE host_allowlist_entries ADD CONSTRAINT host_allowlist_entries_host_check
                CHECK (char_length(host) BETWEEN 1 AND 255 AND host = lower(host) AND host ~ '^[a-z0-9.:\[\]-]+$');

            -- The version row is keyed by its Workspace; the generated `id` lets generic per-table checks address it like any tenant row.
            ALTER TABLE host_allowlist_versions ADD COLUMN id uuid GENERATED ALWAYS AS (workspace_id) STORED;

            ALTER TABLE host_allowlist_entries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE host_allowlist_entries FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON host_allowlist_entries
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            ALTER TABLE host_allowlist_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE host_allowlist_versions FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON host_allowlist_versions
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            DROP FUNCTION IF EXISTS connector_remove_host_allowlist_entry(uuid);

            CREATE FUNCTION connector_remove_host_allowlist_entry(p_entry_id uuid)
            RETURNS boolean
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

                DELETE FROM public.host_allowlist_entries e
                WHERE e.workspace_id = v_workspace AND e.id = p_entry_id;
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed > 0;
            END
            $fn$;

            REVOKE ALL ON FUNCTION connector_remove_host_allowlist_entry(uuid) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION connector_remove_host_allowlist_entry(uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS connector_remove_host_allowlist_entry(uuid)');
        }

        Schema::dropIfExists('host_allowlist_versions');
        Schema::dropIfExists('host_allowlist_entries');
    }
};

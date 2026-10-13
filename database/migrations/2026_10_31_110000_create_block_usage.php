<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| block_usage (Story 6.2), owned by Blocks: how many members have a Block on a dashboard, as a read model fed by `dashboards.dashboard.changed`, so that
| "who is affected" never reads a Dashboards table. One row per (Block, membership) with the count of that member's dashboard items that hold it, removed
| at zero. `block_usage_cursors` holds, per dashboard subject, the last `subject_seq` applied: an event at or below it is dropped (an older one, a replay).
| The template side is the same table with `kind = 'template'` once Templates exist; nothing about it is built here.
|
| Tenant tables: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the workspace_isolation policy. Role `app` keeps SELECT, INSERT and
| UPDATE; a row goes only through `blocks_release_block_usage()`, a SECURITY DEFINER function scoped to the current Workspace.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<'SQL'
            CREATE TABLE block_usage (
                id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                block_id uuid NOT NULL,
                membership_id uuid NOT NULL,
                kind text NOT NULL DEFAULT 'user',
                items integer NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, block_id, kind, membership_id),
                CONSTRAINT block_usage_items_check CHECK (items >= 1),
                CONSTRAINT block_usage_kind_check CHECK (kind IN ('user', 'template'))
            );

            CREATE TABLE block_usage_cursors (
                id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                subject text NOT NULL,
                last_seq bigint NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, subject)
            );

            INSERT INTO block_usage (workspace_id, block_id, membership_id, items)
            SELECT d.workspace_id, i.block_id, d.membership_id, count(*)
            FROM dashboard_items i JOIN dashboards d ON d.id = i.dashboard_id AND d.workspace_id = i.workspace_id
            GROUP BY d.workspace_id, i.block_id, d.membership_id;

            DROP FUNCTION IF EXISTS blocks_release_block_usage(uuid, uuid);
            CREATE FUNCTION blocks_release_block_usage(p_block_id uuid, p_membership_id uuid)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                UPDATE public.block_usage SET items = items - 1, updated_at = now()
                WHERE workspace_id = v_workspace AND block_id = p_block_id AND kind = 'user' AND membership_id = p_membership_id AND items > 1;

                IF FOUND THEN
                    RETURN;
                END IF;

                DELETE FROM public.block_usage
                WHERE workspace_id = v_workspace AND block_id = p_block_id AND kind = 'user' AND membership_id = p_membership_id AND items <= 1;
            END
            $fn$;

            REVOKE ALL ON FUNCTION blocks_release_block_usage(uuid, uuid) FROM PUBLIC;

            -- Clears the Workspace's read model for a rebuild from the events (maintenance only).
            DROP FUNCTION IF EXISTS blocks_reset_block_usage();
            CREATE FUNCTION blocks_reset_block_usage()
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                DELETE FROM public.block_usage WHERE workspace_id = v_workspace;
                DELETE FROM public.block_usage_cursors WHERE workspace_id = v_workspace;
            END
            $fn$;

            REVOKE ALL ON FUNCTION blocks_reset_block_usage() FROM PUBLIC;
            SQL);

        foreach (['block_usage', 'block_usage_cursors'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY workspace_isolation ON {$table}
                    USING ({$workspace}) WITH CHECK ({$workspace});
                SQL);
        }

        DB::unprepared('GRANT EXECUTE ON FUNCTION blocks_release_block_usage(uuid, uuid) TO app');
        DB::unprepared('GRANT EXECUTE ON FUNCTION blocks_reset_block_usage() TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS blocks_release_block_usage(uuid, uuid);
            DROP FUNCTION IF EXISTS blocks_reset_block_usage();
            DROP TABLE IF EXISTS block_usage_cursors CASCADE;
            DROP TABLE IF EXISTS block_usage CASCADE;
            SQL);
    }
};

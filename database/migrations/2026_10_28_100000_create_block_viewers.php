<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| block_viewers (Story 4.13), owned by Results: who has a Block on a dashboard, as a read model fed by `dashboards.dashboard.changed`, so that a
| result update can find its recipients without reading a Dashboards table. One row per (membership, Block) with a count of the member's dashboard
| items that hold it (a duplicated dashboard holds it twice), removed when the count reaches zero. Backfilled once from the dashboards that exist.
|
| Tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the workspace_isolation policy. Role `app` keeps SELECT, INSERT and
| UPDATE; the row goes only through `results_release_block_viewer()`, a SECURITY DEFINER function that is scoped to the current Workspace.
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
            CREATE TABLE block_viewers (
                id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                membership_id uuid NOT NULL,
                block_id uuid NOT NULL,
                items integer NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, block_id, membership_id),
                CONSTRAINT block_viewers_items_check CHECK (items >= 1)
            );
            CREATE INDEX block_viewers_membership_index ON block_viewers (workspace_id, membership_id);

            INSERT INTO block_viewers (workspace_id, membership_id, block_id, items)
            SELECT d.workspace_id, d.membership_id, i.block_id, count(*)
            FROM dashboard_items i JOIN dashboards d ON d.id = i.dashboard_id AND d.workspace_id = i.workspace_id
            GROUP BY d.workspace_id, d.membership_id, i.block_id;

            DROP FUNCTION IF EXISTS results_release_block_viewer(uuid, uuid);
            CREATE FUNCTION results_release_block_viewer(p_membership_id uuid, p_block_id uuid)
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

                UPDATE public.block_viewers SET items = items - 1, updated_at = now()
                WHERE workspace_id = v_workspace AND membership_id = p_membership_id AND block_id = p_block_id AND items > 1;

                IF FOUND THEN
                    RETURN;
                END IF;

                DELETE FROM public.block_viewers
                WHERE workspace_id = v_workspace AND membership_id = p_membership_id AND block_id = p_block_id AND items <= 1;
            END
            $fn$;

            REVOKE ALL ON FUNCTION results_release_block_viewer(uuid, uuid) FROM PUBLIC;
            SQL);

        DB::unprepared(<<<SQL
            ALTER TABLE block_viewers ENABLE ROW LEVEL SECURITY;
            ALTER TABLE block_viewers FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON block_viewers
                USING ({$workspace}) WITH CHECK ({$workspace});
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION results_release_block_viewer(uuid, uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS results_release_block_viewer(uuid, uuid);
            DROP TABLE IF EXISTS block_viewers CASCADE;
            SQL);
    }
};

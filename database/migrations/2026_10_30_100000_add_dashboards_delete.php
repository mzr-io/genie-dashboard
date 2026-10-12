<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Manage my dashboards (Story 4.16).
|
|  dashboards_delete()  the only way the web role removes a dashboard. Role `app` keeps SELECT, INSERT and UPDATE and has no DELETE; this
|                       SECURITY DEFINER function, owned by the migrator, deletes one non-Overview dashboard of one member of the current
|                       Workspace together with its items, and fails without a Workspace context. It refuses an Overview itself, so the rule
|                       holds even if a caller forgets it. It returns whether a dashboard was removed.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS dashboards_delete(uuid, uuid);
            CREATE FUNCTION dashboards_delete(p_dashboard_id uuid, p_membership_id uuid)
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

                IF NOT EXISTS (
                    SELECT 1 FROM public.dashboards d
                    WHERE d.workspace_id = v_workspace AND d.id = p_dashboard_id AND d.membership_id = p_membership_id AND NOT d.is_overview
                ) THEN
                    RETURN false;
                END IF;

                DELETE FROM public.dashboard_items i WHERE i.workspace_id = v_workspace AND i.dashboard_id = p_dashboard_id;
                DELETE FROM public.dashboards d
                WHERE d.workspace_id = v_workspace AND d.id = p_dashboard_id AND d.membership_id = p_membership_id AND NOT d.is_overview;
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed > 0;
            END
            $fn$;

            REVOKE ALL ON FUNCTION dashboards_delete(uuid, uuid) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION dashboards_delete(uuid, uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS dashboards_delete(uuid, uuid)');
    }
};

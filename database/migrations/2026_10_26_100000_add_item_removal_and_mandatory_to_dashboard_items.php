<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Add and remove Blocks (Story 4.9).
|
|  dashboard_items.mandatory  an item an Admin requires (Templates, Epic 7): `RemoveItem` is refused for it. False for every item made here.
|  dashboards_remove_item()   the only way the web role removes an item. Role `app` keeps SELECT, INSERT and UPDATE and has no DELETE (a bug or an
|                             injection cannot empty a table); this SECURITY DEFINER function, owned by the migrator, deletes one item of one
|                             dashboard of the current Workspace only, and fails without a Workspace context. It refuses a mandatory item itself,
|                             so the rule holds even if a caller forgets it.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE dashboard_items ADD COLUMN mandatory boolean NOT NULL DEFAULT false;

            DROP FUNCTION IF EXISTS dashboards_remove_item(uuid, uuid);
            CREATE FUNCTION dashboards_remove_item(p_dashboard_id uuid, p_item_id uuid)
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

                DELETE FROM public.dashboard_items i
                WHERE i.workspace_id = v_workspace AND i.dashboard_id = p_dashboard_id AND i.id = p_item_id AND NOT i.mandatory;
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed > 0;
            END
            $fn$;

            REVOKE ALL ON FUNCTION dashboards_remove_item(uuid, uuid) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION dashboards_remove_item(uuid, uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS dashboards_remove_item(uuid, uuid);
            ALTER TABLE dashboard_items DROP COLUMN IF EXISTS mandatory;
            SQL);
    }
};

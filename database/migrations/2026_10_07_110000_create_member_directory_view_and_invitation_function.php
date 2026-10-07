<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The reads behind User configuration (Story 1.20), owned by Access.
|
|  access_workspace_members      the active Workspace's memberships with the person's name and email. A
|                                `security_invoker` view: the caller's own row-level security on
|                                `workspace_memberships` applies, so an unset or foreign Workspace returns no
|                                rows. It lists no password hash, token or remember token.
|  access_workspace_invitations  the pending invitations (unused, not expired) of the transaction's Workspace.
|                                `invitations` is a global table without row-level security, so the filter lives
|                                in this SECURITY DEFINER function (owned by `migrator`, executable by `app`):
|                                it reads `current_setting('app.workspace_id', true)` and returns no rows when it
|                                is unset. It takes no argument, so a caller cannot ask for another Workspace.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS access_workspace_members;
            DROP FUNCTION IF EXISTS access_workspace_invitations();

            CREATE VIEW access_workspace_members WITH (security_invoker = true) AS
                SELECT m.id,
                       m.workspace_id,
                       u.name,
                       u.email,
                       m.role,
                       CASE WHEN m.status = 'active' THEN 'active' ELSE 'deactivated' END AS status,
                       m.last_active_at
                FROM public.workspace_memberships m
                JOIN public.users u ON u.id = m.user_id;

            CREATE FUNCTION access_workspace_invitations()
            RETURNS TABLE (id uuid, email varchar, role varchar)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
                SELECT i.id, i.email, i.role
                FROM public.invitations i
                WHERE i.workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                  AND i.used_at IS NULL
                  AND i.expires_at > now()
            $fn$;

            REVOKE ALL ON FUNCTION access_workspace_invitations() FROM PUBLIC;
            REVOKE ALL ON access_workspace_members FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION access_workspace_invitations() TO app');
        DB::unprepared('GRANT SELECT ON access_workspace_members TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS access_workspace_members;
            DROP FUNCTION IF EXISTS access_workspace_invitations();
            SQL);
    }
};

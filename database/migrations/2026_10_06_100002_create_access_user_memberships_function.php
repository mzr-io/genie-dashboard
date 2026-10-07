<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The one cross-Workspace lookup (sign-in, Workspace switcher): a user's memberships and their
| Workspaces. Owned by Access, run as the table owner `migrator` (SECURITY DEFINER).
|
| FORCE ROW LEVEL SECURITY applies to the owner too and no role may bypass it, so the function
| opens a second, SELECT-only policy that is limited to the owner role and to the single user
| named by a transaction-local setting that only this function sets. `app` cannot use that
| policy directly: it is not the policy's role.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->requireRoles(['app', 'migrator']);

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS access_user_memberships(bigint);
            DROP POLICY IF EXISTS membership_lookup ON workspace_memberships;

            CREATE POLICY membership_lookup ON workspace_memberships
                FOR SELECT TO migrator
                USING (user_id = nullif(current_setting('app.lookup_user_id', true), '')::bigint);

            CREATE FUNCTION access_user_memberships(p_user_id bigint)
            RETURNS TABLE (
                membership_id uuid,
                workspace_id uuid,
                workspace_name varchar,
                workspace_label varchar,
                workspace_status varchar,
                role varchar,
                status varchar,
                last_active_at timestamp
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            BEGIN
                PERFORM set_config('app.lookup_user_id', p_user_id::text, true);

                RETURN QUERY
                    SELECT m.id, m.workspace_id, w.name, w.label, w.status,
                           m.role, m.status, m.last_active_at
                    FROM public.workspace_memberships m
                    JOIN public.workspaces w ON w.id = m.workspace_id
                    WHERE m.user_id = p_user_id
                    ORDER BY w.name, m.id;

                PERFORM set_config('app.lookup_user_id', '', true);
            END
            $fn$;

            REVOKE ALL ON FUNCTION access_user_memberships(bigint) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION access_user_memberships(bigint) TO app');
    }

    /**
     * @param  list<string>  $roles
     */
    private function requireRoles(array $roles): void
    {
        $present = array_column(DB::select('select rolname from pg_roles where rolname in ('.implode(',', array_fill(0, count($roles), '?')).')', $roles), 'rolname');
        $missing = array_values(array_diff($roles, $present));

        if ($missing !== []) {
            throw new RuntimeException('Missing database role(s): '.implode(', ', $missing).'. Create them with docker/postgres/initdb.sh before migrating.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS access_user_memberships(bigint);
            DROP POLICY IF EXISTS membership_lookup ON workspace_memberships;
            SQL);
    }
};

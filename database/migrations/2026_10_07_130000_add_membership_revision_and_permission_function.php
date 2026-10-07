<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Assigning roles and permissions (Story 1.22).
|
|  workspace_memberships.revision      the optimistic-concurrency counter: starts at 1 and every change of a
|                                      member's role or permissions adds one, so an editor holding an older value
|                                      is refused instead of overwriting another Admin's edit.
|  access_workspace_members            the member list view also exposes `revision` (appended column).
|  access_set_membership_permissions   replaces a membership's permission set. Role `app` has no DELETE on the
|                                      tenant tables, so removing a permission goes through this SECURITY DEFINER
|                                      function (owned by `migrator`, executable by `app`). It reads
|                                      `current_setting('app.workspace_id', true)`, refuses when it is unset, takes
|                                      no Workspace id and touches only memberships of that Workspace, so another
|                                      Workspace's membership id matches nothing.
*/
return new class extends Migration
{
    private const PERMISSIONS = [
        'data_sources.manage', 'blocks.edit', 'blocks.publish', 'templates.manage', 'users.manage',
        'settings.manage', 'audit.view', 'data.preview_as_user', 'access.manage',
    ];

    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $catalogue = implode(', ', array_map(fn (string $p): string => "'{$p}'", self::PERMISSIONS));

        DB::unprepared(<<<SQL
            DROP FUNCTION IF EXISTS access_set_membership_permissions(uuid, jsonb);

            CREATE OR REPLACE VIEW access_workspace_members WITH (security_invoker = true) AS
                SELECT m.id,
                       m.workspace_id,
                       u.name,
                       u.email,
                       m.role,
                       CASE WHEN m.status = 'active' THEN 'active' ELSE 'deactivated' END AS status,
                       m.last_active_at,
                       m.revision
                FROM public.workspace_memberships m
                JOIN public.users u ON u.id = m.user_id;

            CREATE FUNCTION access_set_membership_permissions(p_membership_id uuid, p_permissions jsonb)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS \$fn\$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_role varchar;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                IF p_permissions IS NULL OR jsonb_typeof(p_permissions) <> 'array' THEN
                    RAISE EXCEPTION 'Permissions must be a JSON array' USING ERRCODE = '22023';
                END IF;

                -- Strings from the closed catalogue only (no JSON nulls, numbers or objects).
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(p_permissions) e WHERE jsonb_typeof(e) <> 'string')
                   OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(p_permissions) t WHERE t NOT IN ({$catalogue})) THEN
                    RAISE EXCEPTION 'Permissions come from the closed catalogue' USING ERRCODE = '22023';
                END IF;

                SELECT m.role INTO v_role
                FROM public.workspace_memberships m
                WHERE m.id = p_membership_id AND m.workspace_id = v_workspace;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'No such membership in this Workspace' USING ERRCODE = '42501';
                END IF;

                -- A User holds no permissions.
                IF v_role = 'user' AND jsonb_array_length(p_permissions) > 0 THEN
                    RAISE EXCEPTION 'A User holds no permissions' USING ERRCODE = '22023';
                END IF;

                DELETE FROM public.membership_permissions mp
                WHERE mp.membership_id = p_membership_id
                  AND mp.workspace_id = v_workspace
                  AND mp.permission NOT IN (SELECT jsonb_array_elements_text(p_permissions));

                INSERT INTO public.membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at)
                SELECT gen_random_uuid(), v_workspace, p_membership_id, wanted.permission, now(), now()
                FROM (SELECT DISTINCT jsonb_array_elements_text(p_permissions) AS permission) wanted
                ON CONFLICT (membership_id, permission) DO NOTHING;
            END
            \$fn\$;

            REVOKE ALL ON FUNCTION access_set_membership_permissions(uuid, jsonb) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION access_set_membership_permissions(uuid, jsonb) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP FUNCTION IF EXISTS access_set_membership_permissions(uuid, jsonb);
                DROP VIEW IF EXISTS access_workspace_members;
                CREATE VIEW access_workspace_members WITH (security_invoker = true) AS
                    SELECT m.id, m.workspace_id, u.name, u.email, m.role,
                           CASE WHEN m.status = 'active' THEN 'active' ELSE 'deactivated' END AS status,
                           m.last_active_at
                    FROM public.workspace_memberships m
                    JOIN public.users u ON u.id = m.user_id;
                REVOKE ALL ON access_workspace_members FROM PUBLIC;
                GRANT SELECT ON access_workspace_members TO app;
                SQL);
        }

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};

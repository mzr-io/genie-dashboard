<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Inviting users (Story 1.21).
|
| `invitations` stays a global table without row-level security (a link is opened before any Workspace context
| exists), and `app` still cannot INSERT into it or UPDATE anything but `used_at`. Creating, replacing and revoking
| an invitation therefore go through three SECURITY DEFINER functions owned by `migrator` and executable by `app`.
| Each reads `current_setting('app.workspace_id', true)` and refuses when it is unset; none takes a Workspace id, so
| a caller cannot name another Workspace, and an invitation id from another Workspace matches nothing.
|
|  permissions  the Admin permissions the invitation grants: a jsonb array of catalogue values only (CHECK). The
|               default is every permission, which is what the operator's first-Admin invitation (it names none) gets.
|  revoked_at   set by revocation. A usable invitation has `used_at` and `revoked_at` null and `expires_at` ahead.
|
|  access_create_invitation   inserts a hashed invitation; when the email already has a usable invitation in the
|                             Workspace it inserts nothing and returns that one (`was_created` false).
|  access_replace_invitation  puts a new token hash and expiry on a usable invitation (the old link stops working).
|  access_revoke_invitation   sets `revoked_at` on a usable invitation.
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
            ALTER TABLE invitations ADD COLUMN revoked_at timestamptz NULL;
            ALTER TABLE invitations ADD COLUMN permissions jsonb NOT NULL DEFAULT '["data_sources.manage","blocks.edit","blocks.publish","templates.manage","users.manage","settings.manage","audit.view","data.preview_as_user","access.manage"]'::jsonb;
            ALTER TABLE invitations ADD CONSTRAINT invitations_permissions_check
                CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '["data_sources.manage","blocks.edit","blocks.publish","templates.manage","users.manage","settings.manage","audit.view","data.preview_as_user","access.manage"]'::jsonb);

            -- Only usable invitations are pending: unused, not revoked and not expired.
            CREATE OR REPLACE FUNCTION access_workspace_invitations()
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
                  AND i.revoked_at IS NULL
                  AND i.expires_at > now()
            $fn$;

            -- `migrate:fresh` drops tables, not functions: make the creation repeatable.
            DROP FUNCTION IF EXISTS access_create_invitation(uuid, varchar, char, varchar, jsonb, varchar, timestamptz);
            DROP FUNCTION IF EXISTS access_replace_invitation(uuid, char, timestamptz, varchar);
            DROP FUNCTION IF EXISTS access_revoke_invitation(uuid);

            CREATE FUNCTION access_create_invitation(
                p_id uuid, p_email varchar, p_token_hash char(64), p_role varchar,
                p_permissions jsonb, p_created_by varchar, p_expires_at timestamptz
            )
            RETURNS TABLE (invitation_id uuid, was_created boolean)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_existing uuid;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'access_create_invitation needs a Workspace context' USING ERRCODE = '42501';
                END IF;

                IF p_expires_at <= now() THEN
                    RAISE EXCEPTION 'an invitation must expire in the future' USING ERRCODE = '22023';
                END IF;

                -- The inviter of record is an active Admin membership of this Workspace, never a free-form string.
                IF p_created_by IS NULL OR p_created_by !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
                    RAISE EXCEPTION 'access_create_invitation needs the inviting membership' USING ERRCODE = '42501';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.workspace_memberships m
                    WHERE m.id = p_created_by::uuid AND m.workspace_id = v_workspace AND m.status = 'active' AND m.role = 'admin'
                ) THEN
                    RAISE EXCEPTION 'access_create_invitation: the inviter is not an active Admin of this Workspace' USING ERRCODE = '42501';
                END IF;

                -- One creator at a time per Workspace and email, so two requests cannot both insert.
                PERFORM pg_advisory_xact_lock(hashtextextended(v_workspace::text || '|' || lower(p_email), 0));

                SELECT i.id INTO v_existing
                FROM public.invitations i
                WHERE i.workspace_id = v_workspace
                  AND lower(i.email) = lower(p_email)
                  AND i.used_at IS NULL
                  AND i.revoked_at IS NULL
                  AND i.expires_at > now()
                LIMIT 1;

                IF v_existing IS NOT NULL THEN
                    RETURN QUERY SELECT v_existing, false;
                    RETURN;
                END IF;

                INSERT INTO public.invitations (id, workspace_id, email, token_hash, role, permissions, expires_at, created_by, created_at, updated_at)
                VALUES (p_id, v_workspace, lower(p_email), p_token_hash, p_role, p_permissions, p_expires_at, p_created_by, now(), now());

                RETURN QUERY SELECT p_id, true;
            END
            $fn$;

            CREATE FUNCTION access_replace_invitation(
                p_invitation_id uuid, p_token_hash char(64), p_expires_at timestamptz, p_created_by varchar
            )
            RETURNS TABLE (invitation_id uuid, invitee_email varchar, invitee_role varchar, granted_permissions jsonb, previous_created_by varchar)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_previous varchar;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'access_replace_invitation needs a Workspace context' USING ERRCODE = '42501';
                END IF;

                IF p_expires_at <= now() THEN
                    RAISE EXCEPTION 'an invitation must expire in the future' USING ERRCODE = '22023';
                END IF;

                -- The inviter of record is an active Admin membership of this Workspace, never a free-form string.
                IF p_created_by IS NULL OR p_created_by !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
                    RAISE EXCEPTION 'access_replace_invitation needs the inviting membership' USING ERRCODE = '42501';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.workspace_memberships m
                    WHERE m.id = p_created_by::uuid AND m.workspace_id = v_workspace AND m.status = 'active' AND m.role = 'admin'
                ) THEN
                    RAISE EXCEPTION 'access_replace_invitation: the inviter is not an active Admin of this Workspace' USING ERRCODE = '42501';
                END IF;

                SELECT i.created_by INTO v_previous
                FROM public.invitations i
                WHERE i.id = p_invitation_id
                  AND i.workspace_id = v_workspace
                  AND i.used_at IS NULL
                  AND i.revoked_at IS NULL
                  AND i.expires_at > now()
                FOR UPDATE;

                RETURN QUERY
                WITH replaced AS (
                    UPDATE public.invitations i
                    SET token_hash = p_token_hash, expires_at = p_expires_at, created_by = p_created_by, updated_at = now()
                    WHERE i.id = p_invitation_id
                      AND i.workspace_id = v_workspace
                      AND i.used_at IS NULL
                      AND i.revoked_at IS NULL
                      AND i.expires_at > now()
                    RETURNING i.id, i.email, i.role, i.permissions
                )
                SELECT r.id, r.email, r.role, r.permissions, v_previous FROM replaced r;
            END
            $fn$;

            CREATE FUNCTION access_revoke_invitation(p_invitation_id uuid)
            RETURNS TABLE (invitation_id uuid, invitee_role varchar)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'access_revoke_invitation needs a Workspace context' USING ERRCODE = '42501';
                END IF;

                RETURN QUERY
                WITH revoked AS (
                    UPDATE public.invitations i
                    SET revoked_at = now(), updated_at = now()
                    WHERE i.id = p_invitation_id
                      AND i.workspace_id = v_workspace
                      AND i.used_at IS NULL
                      AND i.revoked_at IS NULL
                      AND i.expires_at > now()
                    RETURNING i.id, i.role
                )
                SELECT r.id, r.role FROM revoked r;
            END
            $fn$;

            REVOKE ALL ON FUNCTION access_create_invitation(uuid, varchar, char, varchar, jsonb, varchar, timestamptz) FROM PUBLIC;
            REVOKE ALL ON FUNCTION access_replace_invitation(uuid, char, timestamptz, varchar) FROM PUBLIC;
            REVOKE ALL ON FUNCTION access_revoke_invitation(uuid) FROM PUBLIC;
            SQL);

        DB::unprepared(<<<'SQL'
            GRANT EXECUTE ON FUNCTION access_create_invitation(uuid, varchar, char, varchar, jsonb, varchar, timestamptz) TO app;
            GRANT EXECUTE ON FUNCTION access_replace_invitation(uuid, char, timestamptz, varchar) TO app;
            GRANT EXECUTE ON FUNCTION access_revoke_invitation(uuid) TO app;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS access_create_invitation(uuid, varchar, char, varchar, jsonb, varchar, timestamptz);
            DROP FUNCTION IF EXISTS access_replace_invitation(uuid, char, timestamptz, varchar);
            DROP FUNCTION IF EXISTS access_revoke_invitation(uuid);

            CREATE OR REPLACE FUNCTION access_workspace_invitations()
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

            ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_permissions_check;
            ALTER TABLE invitations DROP COLUMN IF EXISTS permissions;
            ALTER TABLE invitations DROP COLUMN IF EXISTS revoked_at;
            SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Organising users into groups (Story 1.23), owned by Access.
|
|  user_groups                 a Workspace's groups. The table is `user_groups` because `groups` is a reserved word.
|                              The name is unique per Workspace case-insensitively and trimmed (a functional unique
|                              index), 1 to 64 characters, stored trimmed.
|  group_members               which memberships belong to which group; one row per pair.
|  Both are tenant tables: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. The
|  composite foreign keys tie a group and a membership to the row's own Workspace, so no row can pair a group of one
|  Workspace with a member of another.
|
|  Role `app` has SELECT, INSERT and UPDATE only (no DELETE on tenant tables). Removals go through two SECURITY
|  DEFINER functions owned by `migrator` and executable by `app`. They read `current_setting('app.workspace_id', true)`,
|  refuse when it is unset, take no Workspace argument and touch only that Workspace's rows, so another Workspace's
|  group or membership ID matches nothing:
|
|    access_remove_group_member(group, membership)  true when a pair was removed, false when it was not there.
|    access_delete_group(group)                      removes the group and its memberships; the number of
|                                                    memberships removed, or NULL when there is no such group.
|
|  Epic 6 hook: access_delete_group is the single place that must refuse (or report) a delete of a group that an
|  access grant references. No grants exist yet, so it removes memberships only.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('name', 64);
            $table->timestamps();

            $table->index('workspace_id');
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->foreignUuid('group_id')->constrained('user_groups');
            $table->foreignUuid('membership_id')->constrained('workspace_memberships');
            $table->timestamps();

            $table->unique(['group_id', 'membership_id']);
            $table->index('workspace_id');
            $table->index('membership_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE user_groups ADD CONSTRAINT user_groups_name_check
                CHECK (char_length(name) BETWEEN 1 AND 64 AND name = btrim(name) AND name !~ '[[:cntrl:]]'
                    AND name !~ '[\u200B-\u200F\u2028-\u202E\u2060-\u2064\uFEFF]');
            CREATE UNIQUE INDEX user_groups_workspace_name_unique ON user_groups (workspace_id, lower(btrim(name)));
            ALTER TABLE user_groups ADD CONSTRAINT user_groups_workspace_id_id_unique UNIQUE (workspace_id, id);
            ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_workspace_id_id_unique UNIQUE (workspace_id, id);
            ALTER TABLE group_members ADD CONSTRAINT group_members_group_same_workspace
                FOREIGN KEY (workspace_id, group_id) REFERENCES user_groups (workspace_id, id);
            ALTER TABLE group_members ADD CONSTRAINT group_members_membership_same_workspace
                FOREIGN KEY (workspace_id, membership_id) REFERENCES workspace_memberships (workspace_id, id);

            ALTER TABLE user_groups ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_groups FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON user_groups
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            ALTER TABLE group_members ENABLE ROW LEVEL SECURITY;
            ALTER TABLE group_members FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON group_members
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            DROP FUNCTION IF EXISTS access_remove_group_member(uuid, uuid);
            DROP FUNCTION IF EXISTS access_delete_group(uuid);

            CREATE FUNCTION access_remove_group_member(p_group_id uuid, p_membership_id uuid)
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

                DELETE FROM public.group_members gm
                WHERE gm.workspace_id = v_workspace
                  AND gm.group_id = p_group_id
                  AND gm.membership_id = p_membership_id;
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed > 0;
            END
            $fn$;

            CREATE FUNCTION access_delete_group(p_group_id uuid)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_members integer;
                v_deleted integer;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                -- Epic 6 hook: refuse here (or report) when an access grant references this group.

                DELETE FROM public.group_members gm
                WHERE gm.workspace_id = v_workspace AND gm.group_id = p_group_id;
                GET DIAGNOSTICS v_members = ROW_COUNT;

                DELETE FROM public.user_groups g
                WHERE g.workspace_id = v_workspace AND g.id = p_group_id;
                GET DIAGNOSTICS v_deleted = ROW_COUNT;

                IF v_deleted = 0 THEN
                    RETURN NULL;
                END IF;

                RETURN v_members;
            END
            $fn$;

            REVOKE ALL ON FUNCTION access_remove_group_member(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION access_delete_group(uuid) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION access_remove_group_member(uuid, uuid) TO app');
        DB::unprepared('GRANT EXECUTE ON FUNCTION access_delete_group(uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP FUNCTION IF EXISTS access_remove_group_member(uuid, uuid);
                DROP FUNCTION IF EXISTS access_delete_group(uuid);
                SQL);
        }

        Schema::dropIfExists('group_members');
        Schema::dropIfExists('user_groups');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE workspace_memberships DROP CONSTRAINT IF EXISTS workspace_memberships_workspace_id_id_unique');
        }
    }
};

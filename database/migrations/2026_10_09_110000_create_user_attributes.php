<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| User attributes for user-context binding (Story 2.12), owned by Access.
|
|  user_attribute_keys  the Workspace's catalogue of attributes (a region, an employee number). `key_id` (a slug) and
|                       `value_type` (text, identifier or integer) are fixed at creation: a trigger fails any UPDATE that
|                       changes them, for any role. Only `label` (and the `revision` it bumps) changes. There is no delete.
|  user_attributes      one value per membership and key, sealed (libsodium secretbox, under the `data` key) in
|                       `value_ciphertext`, with a keyed blind index (HMAC-SHA256 under the `digest` key, hex) in
|                       `value_blind_index` so equal values can be found without decrypting. `blind_index_version` names the
|                       recipe, so a later key rotation can tell old indexes from new.
|
|  Both are tenant tables: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. The
|  composite foreign keys tie a value to a membership and a key of its own Workspace. Role `app` has SELECT, INSERT and
|  UPDATE only (no DELETE on tenant tables): a member's values are removed through a SECURITY DEFINER function owned by
|  `migrator` and executable by `app`. It reads `current_setting('app.workspace_id', true)`, refuses when it is unset,
|  takes no Workspace argument and touches only that Workspace's rows:
|
|    access_delete_member_attributes(membership)  the number of values removed.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_attribute_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->text('key_id');
            $table->string('label', 64);
            $table->string('value_type', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index('workspace_id');
        });

        Schema::create('user_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('membership_id');
            $table->uuid('attribute_key_id');
            $table->binary('value_ciphertext');
            $table->text('value_blind_index');
            $table->unsignedInteger('blind_index_version');
            $table->timestamp('updated_at');

            $table->unique(['membership_id', 'attribute_key_id']);
            $table->index('workspace_id');
            $table->index(['attribute_key_id', 'value_blind_index']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_key_id_check
                CHECK (key_id ~ '^[a-z][a-z0-9_]{0,47}$');
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_label_check
                CHECK (char_length(label) BETWEEN 1 AND 64 AND label = btrim(label) AND label !~ '[[:cntrl:]]'
                    AND label !~ '[\u200B-\u200F\u2028-\u202E\u2060-\u2064\uFEFF]');
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_value_type_check
                CHECK (value_type IN ('text', 'identifier', 'integer'));
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_revision_check CHECK (revision >= 1);
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_workspace_key_unique UNIQUE (workspace_id, key_id);
            CREATE UNIQUE INDEX user_attribute_keys_workspace_label_unique ON user_attribute_keys (workspace_id, lower(btrim(label)));
            ALTER TABLE user_attribute_keys ADD CONSTRAINT user_attribute_keys_workspace_id_id_unique UNIQUE (workspace_id, id);

            -- The key id and the value type never change after creation, whatever the role.
            DROP FUNCTION IF EXISTS user_attribute_keys_immutable();
            CREATE FUNCTION user_attribute_keys_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                    OR NEW.key_id IS DISTINCT FROM OLD.key_id
                    OR NEW.value_type IS DISTINCT FROM OLD.value_type THEN
                    RAISE EXCEPTION 'The key id and the value type of a user attribute key never change' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER user_attribute_keys_immutable BEFORE UPDATE ON user_attribute_keys
                FOR EACH ROW EXECUTE FUNCTION user_attribute_keys_immutable();

            ALTER TABLE user_attributes ADD CONSTRAINT user_attributes_membership_same_workspace
                FOREIGN KEY (workspace_id, membership_id) REFERENCES workspace_memberships (workspace_id, id);
            ALTER TABLE user_attributes ADD CONSTRAINT user_attributes_key_same_workspace
                FOREIGN KEY (workspace_id, attribute_key_id) REFERENCES user_attribute_keys (workspace_id, id);
            ALTER TABLE user_attributes ADD CONSTRAINT user_attributes_blind_index_version_check CHECK (blind_index_version >= 1);

            ALTER TABLE user_attribute_keys ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_attribute_keys FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON user_attribute_keys
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            ALTER TABLE user_attributes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_attributes FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON user_attributes
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            DROP FUNCTION IF EXISTS access_delete_member_attributes(uuid);

            CREATE FUNCTION access_delete_member_attributes(p_membership_id uuid)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_deleted integer;
            BEGIN
                IF v_workspace IS NULL THEN
                    RAISE EXCEPTION 'The Workspace context is not set' USING ERRCODE = '42501';
                END IF;

                DELETE FROM public.user_attributes a
                WHERE a.workspace_id = v_workspace AND a.membership_id = p_membership_id;
                GET DIAGNOSTICS v_deleted = ROW_COUNT;

                RETURN v_deleted;
            END
            $fn$;

            REVOKE ALL ON FUNCTION access_delete_member_attributes(uuid) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION access_delete_member_attributes(uuid) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS access_delete_member_attributes(uuid)');
        }

        Schema::dropIfExists('user_attributes');
        Schema::dropIfExists('user_attribute_keys');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS user_attribute_keys_immutable()');
        }
    }
};

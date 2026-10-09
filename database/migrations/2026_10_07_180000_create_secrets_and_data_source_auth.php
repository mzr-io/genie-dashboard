<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Authentication and write-only secrets of a Data Source (Story 2.4), owned by Connector.
|
|  data_sources.api_key_name / api_key_placement   the header or query parameter an API key travels in (not secret).
|  secrets                                         the sealed credentials. `ciphertext` is a libsodium sealed box
|                                                  (only worker-connector holds the private key), `purpose` is fixed
|                                                  to `cred`, `key_version` is the version of the platform key the
|                                                  value was sealed to and `key_ref` is the fingerprint of that public
|                                                  key (the "wrapped DEK ref"). Unique per (data_source_id, slot).
|                                                  Nothing reads `ciphertext` back to a client.
|
|  A tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. Role `app` has
|  SELECT, INSERT and UPDATE only. Removal goes through connector_remove_secrets(), a SECURITY DEFINER function owned
|  by `migrator` and executable by `app`: it reads `app.workspace_id`, takes no Workspace argument and touches only
|  that Workspace's rows.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('api_key_name', 128)->nullable();
            $table->string('api_key_placement', 8)->nullable();
        });

        Schema::create('secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('data_source_id');
            $table->string('slot', 160);
            $table->string('purpose', 16)->default('cred');
            $table->unsignedInteger('key_version');
            $table->string('key_ref', 64);
            $table->binary('ciphertext');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['data_source_id', 'slot']);
            $table->index('workspace_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_api_key_check
                CHECK ((api_key_name IS NULL) = (api_key_placement IS NULL)
                    AND (api_key_placement IS NULL OR api_key_placement IN ('header', 'query')));

            -- A secret belongs to a Data Source of its own Workspace: foreign keys ignore row-level security, so the pair is the key.
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_workspace_id_id_unique UNIQUE (workspace_id, id);
            ALTER TABLE secrets ADD CONSTRAINT secrets_data_source_workspace_fk
                FOREIGN KEY (workspace_id, data_source_id) REFERENCES data_sources (workspace_id, id);

            ALTER TABLE secrets ADD CONSTRAINT secrets_purpose_check CHECK (purpose = 'cred');
            ALTER TABLE secrets ADD CONSTRAINT secrets_slot_check
                CHECK (slot ~ '^(api_key|bearer_token|basic_username|basic_password|header:[!#$%&''*+.^_`|~0-9a-z-]{1,128})$');
            ALTER TABLE secrets ADD CONSTRAINT secrets_key_version_check CHECK (key_version >= 1);
            ALTER TABLE secrets ADD CONSTRAINT secrets_ciphertext_check CHECK (octet_length(ciphertext) > 0);

            ALTER TABLE secrets ENABLE ROW LEVEL SECURITY;
            ALTER TABLE secrets FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON secrets
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            DROP FUNCTION IF EXISTS connector_remove_secrets(uuid, text[]);

            CREATE FUNCTION connector_remove_secrets(p_data_source_id uuid, p_slots text[])
            RETURNS integer
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

                DELETE FROM public.secrets s
                WHERE s.workspace_id = v_workspace AND s.data_source_id = p_data_source_id AND s.slot = ANY (p_slots);
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed;
            END
            $fn$;

            REVOKE ALL ON FUNCTION connector_remove_secrets(uuid, text[]) FROM PUBLIC;
            SQL);

        DB::unprepared('GRANT EXECUTE ON FUNCTION connector_remove_secrets(uuid, text[]) TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS connector_remove_secrets(uuid, text[])');
        }

        Schema::dropIfExists('secrets');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_workspace_id_id_unique');
        }

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['api_key_name', 'api_key_placement']);
        });
    }
};

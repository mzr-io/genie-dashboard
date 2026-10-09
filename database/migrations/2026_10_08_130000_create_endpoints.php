<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Endpoints of a Data Source (Story 2.9), owned by Connector: the requests Blocks will later select.
|
|  endpoints           one row per Endpoint: the Data Source it belongs to, `revision` (the number of the current
|                      revision, starting at 1) and `current_revision_id` (the pointer, set in the same transaction as
|                      the revision it names, so the foreign key is deferred). Role `app` has SELECT, INSERT, UPDATE.
|  endpoint_revisions  immutable: one row per save, `revision` unique per Endpoint. The method (GET or POST), the path
|                      template and the path parsed once into a URL AST, the parameter and header bindings, an optional
|                      typed JSON body template and the read-only flag. Role `app` has SELECT and INSERT only, and a
|                      trigger fails every UPDATE and DELETE, for any role.
|
|  Both are tenant tables: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. Every
|  foreign key carries the Workspace (foreign keys ignore row-level security), so a row can only point inside its own.
|  `created_by_membership_id` is a plain column: no foreign key across modules. There is no delete.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('data_source_id');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('current_revision_id');
            $table->uuid('created_by_membership_id');
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'data_source_id']);
        });

        Schema::create('endpoint_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('endpoint_id');
            $table->unsignedInteger('revision');
            $table->string('method', 8);
            $table->string('path_template', 1024);
            $table->jsonb('path_ast');
            $table->jsonb('params')->default('[]');
            $table->jsonb('headers')->default('[]');
            $table->jsonb('body_template')->nullable();
            $table->boolean('read_only_query')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->uuid('created_by_membership_id');

            $table->index('workspace_id');
            $table->unique(['endpoint_id', 'revision']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE endpoints ADD CONSTRAINT endpoints_workspace_id_id_unique UNIQUE (workspace_id, id);
            ALTER TABLE endpoints ADD CONSTRAINT endpoints_data_source_workspace_fk
                FOREIGN KEY (workspace_id, data_source_id) REFERENCES data_sources (workspace_id, id);
            ALTER TABLE endpoints ADD CONSTRAINT endpoints_revision_check CHECK (revision >= 1);

            -- The pointer's key: the revision must belong to this Endpoint and be the one numbered `endpoints.revision`.
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_pointer_key_unique UNIQUE (workspace_id, endpoint_id, revision, id);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_endpoint_workspace_fk
                FOREIGN KEY (workspace_id, endpoint_id) REFERENCES endpoints (workspace_id, id);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_revision_check CHECK (revision >= 1);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_method_check CHECK (method IN ('GET', 'POST'));
            -- Dashflow never sends a request that changes data: a POST is a read-only query, and only a POST has a body.
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_post_readonly_check
                CHECK (method = 'GET' OR read_only_query);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_get_no_body_check
                CHECK (method = 'POST' OR body_template IS NULL);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_path_check
                CHECK (path_template ~ '^/' AND path_template !~ '^//' AND char_length(path_template) <= 1024);
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_json_check
                CHECK (jsonb_typeof(path_ast) = 'array' AND jsonb_typeof(params) = 'array' AND jsonb_typeof(headers) = 'array');

            -- The pointer names a revision of the same Endpoint, with the current number, checked at commit (the revision is written after the Endpoint).
            ALTER TABLE endpoints ADD CONSTRAINT endpoints_current_revision_fk
                FOREIGN KEY (workspace_id, id, revision, current_revision_id) REFERENCES endpoint_revisions (workspace_id, endpoint_id, revision, id)
                DEFERRABLE INITIALLY DEFERRED;

            -- Immutable: no role can change or remove a revision. `migrate:fresh` drops tables, not functions.
            DROP FUNCTION IF EXISTS endpoint_revisions_immutable();
            CREATE FUNCTION endpoint_revisions_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'endpoint_revisions rows are immutable' USING ERRCODE = '23514';
            END
            $$;

            CREATE TRIGGER endpoint_revisions_immutable BEFORE UPDATE OR DELETE ON endpoint_revisions
                FOR EACH ROW EXECUTE FUNCTION endpoint_revisions_immutable();

            REVOKE UPDATE ON endpoint_revisions FROM app;

            ALTER TABLE endpoints ENABLE ROW LEVEL SECURITY;
            ALTER TABLE endpoints FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON endpoints
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);

            ALTER TABLE endpoint_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE endpoint_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON endpoint_revisions
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE endpoints DROP CONSTRAINT IF EXISTS endpoints_current_revision_fk');
        }

        Schema::dropIfExists('endpoint_revisions');
        Schema::dropIfExists('endpoints');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS endpoint_revisions_immutable()');
        }
    }
};

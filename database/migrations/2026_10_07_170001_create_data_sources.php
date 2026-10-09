<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Data Sources (Story 2.3), owned by Connector: the APIs a Workspace reads.
|
|  `base_url` is the normal form of the Admin's URL; `scheme`, `host` (lower case) and `port` (always resolved: the
|  scheme's default when omitted) are derived from it, so the allowlist dependents query and later fetches need no
|  parsing. `auth_type` is a column now (the five epic values, only `none` accepted until Story 2.4). `default_headers`
|  is a JSON array of {name, value} in the order entered (never credentials). The three limits are optional: null
|  means "use the platform setting". `revision` starts at 1 and rises with every edit (the later `data_source_revision`).
|  `created_by_membership_id` is a plain column: no foreign key across modules.
|
|  A tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. Role `app` has
|  SELECT, INSERT and UPDATE only: no delete exists in this story. The name is unique per Workspace, case-insensitively.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('name', 64);
            $table->string('base_url', 2048);
            $table->string('scheme', 5);
            $table->string('host', 253);
            $table->unsignedInteger('port');
            $table->string('auth_type', 32)->default('none');
            $table->jsonb('default_headers')->default('[]');
            $table->unsignedBigInteger('timeout_seconds')->nullable();
            $table->unsignedBigInteger('max_response_bytes')->nullable();
            $table->unsignedBigInteger('max_pages')->nullable();
            $table->boolean('live_capable')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('created_by_membership_id');
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'host', 'port']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_name_check
                CHECK (char_length(name) BETWEEN 1 AND 64 AND name = btrim(name) AND name !~ '[[:cntrl:]]'
                    AND name !~ '[\u200B-\u200F\u2028-\u202E\u2060-\u2064\uFEFF\u00A0\u1680\u2000-\u200A\u202F\u205F\u3000]');
            CREATE UNIQUE INDEX data_sources_workspace_name_unique ON data_sources (workspace_id, lower(btrim(name)));
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_scheme_check CHECK (scheme IN ('http', 'https'));
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_port_check CHECK (port BETWEEN 1 AND 65535);
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_host_check
                CHECK (char_length(host) BETWEEN 1 AND 255 AND host = lower(host) AND host ~ '^[a-z0-9.:\[\]-]+$');
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_auth_type_check
                CHECK (auth_type IN ('none', 'api_key', 'bearer', 'basic', 'oauth2_client_credentials'));
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_default_headers_check
                CHECK (jsonb_typeof(default_headers) = 'array');
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_limits_check
                CHECK ((timeout_seconds IS NULL OR timeout_seconds > 0)
                    AND (max_response_bytes IS NULL OR max_response_bytes > 0)
                    AND (max_pages IS NULL OR max_pages > 0));
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_revision_check CHECK (revision >= 1);

            ALTER TABLE data_sources ENABLE ROW LEVEL SECURITY;
            ALTER TABLE data_sources FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON data_sources
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};

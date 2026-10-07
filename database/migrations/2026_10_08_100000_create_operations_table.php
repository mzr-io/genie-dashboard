<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Operations (Story 2.5), owned by the Platform kernel: an asynchronous job started by one person, run by a worker.
|
|  operations   one row per request: the `kind` (registered by a module), the membership that asked for it
|               (`requester_membership_id`, a plain column: no foreign key across modules), the subject it concerns
|               (`subject_type`, `subject_id` and `subject_revision`, the last two optional), its `status`
|               (queued, running, succeeded, failed, stale, expired), when it stops being readable (`expires_at`)
|               and the `request_id` it was started under. `result` is a small summary (a few enums and numbers):
|               never a body, a secret, a ciphertext or a URL query.
|
|  A tenant table: non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the Workspace policy. Role `app`
|  has SELECT, INSERT and UPDATE only; the sweep that deletes old rows runs as `maintenance`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('kind', 48);
            $table->uuid('requester_membership_id');
            $table->string('subject_type', 32);
            $table->uuid('subject_id')->nullable();
            $table->unsignedInteger('subject_revision')->nullable();
            $table->string('status', 16)->default('queued');
            $table->timestampTz('expires_at');
            $table->string('request_id', 64)->nullable();
            $table->jsonb('result')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'requester_membership_id']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'migrator')"), 'rolname');
        if (array_diff(['app', 'migrator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and migrator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE operations ADD CONSTRAINT operations_status_check
                CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'stale', 'expired'));
            ALTER TABLE operations ADD CONSTRAINT operations_kind_check CHECK (kind ~ '^[a-z][a-z0-9_]{0,47}$');
            ALTER TABLE operations ADD CONSTRAINT operations_subject_type_check CHECK (subject_type ~ '^[a-z][a-z0-9_]{0,31}$');
            ALTER TABLE operations ADD CONSTRAINT operations_result_check
                CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 2048));

            ALTER TABLE operations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE operations FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON operations
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};

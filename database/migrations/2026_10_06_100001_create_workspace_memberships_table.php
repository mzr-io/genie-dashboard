<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The first tenant table. Every tenant table has a non-null workspace_id, ENABLE and FORCE
| ROW LEVEL SECURITY, and the policy below. An unset `app.workspace_id` matches no row.
|
| `nullif(..., '')` is deliberate: after a transaction that used set_config(..., true) ends,
| PostgreSQL leaves the setting as an empty string (not NULL) on that session, and
| ''::uuid raises an error instead of matching nothing. A pooled connection reused after
| any Workspace transaction would otherwise fail every context-free query.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            // users.id stays bigint until the identity stories convert it (deferred work).
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 10);
            $table->string('status', 20)->default('active');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_role_check
                CHECK (role IN ('user', 'admin'));
            ALTER TABLE workspace_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE workspace_memberships FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON workspace_memberships
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Two global tables (Story 1.12).
|
|  invitations     single-use, email-bound invitations. Only the SHA-256 hash of the 256-bit token is
|                  stored. `app` reads and marks them used (SELECT, UPDATE); it never creates one. Only
|                  the operator command creates invitations, as role `operator`.
|  operator_audit  the operator's own log (Workspace creation). `app` has no privilege on it at all;
|                  `operator` can only INSERT.
|
| `invitations.workspace_id` names the Workspace the invitee joins; the table is global (no
| row-level security) because a link is opened before any Workspace context exists.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('email', 254);
            $table->char('token_hash', 64)->unique();
            $table->string('role', 10);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });

        Schema::create('operator_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action', 100);
            $table->string('actor', 64);
            $table->uuid('workspace_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('occurred_at');

            $table->index('occurred_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'operator')"), 'rolname');
        if (array_diff(['app', 'operator'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and operator. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('user', 'admin'));

            -- Default privileges gave `app` SELECT, INSERT and UPDATE; it may not create invitations
            -- and has no access to the operator's log.
            REVOKE INSERT ON invitations FROM app;
            REVOKE ALL ON operator_audit FROM app;

            GRANT INSERT ON workspaces, invitations, operator_audit TO operator;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_audit');
        Schema::dropIfExists('invitations');
    }
};

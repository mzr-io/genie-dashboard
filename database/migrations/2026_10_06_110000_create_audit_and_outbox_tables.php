<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Audit and outbox kernels (Story 1.11). All three are tenant tables: non-null workspace_id, ENABLE and
| FORCE ROW LEVEL SECURITY, and the Workspace policy used by every tenant table.
|
|  audit_events        append-only for `app`: INSERT and SELECT, no UPDATE, no DELETE.
|  outbox_events       `app` inserts and reads; only the relay (role `system`) updates `sent_at`.
|  outbox_consumptions the dedupe record `(consumer, event_id)` and the last applied `subject_seq`.
|
| Role `system` is the outbox relay. It gets column-limited grants on outbox_events (the relay columns
| and UPDATE on sent_at) and policies limited to that role, so it can only mark unsent events sent (it reads
| across Workspaces, columns limited, and changes nothing else). It never bypasses row-level security.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('action', 100);
            $table->string('actor', 64)->nullable();
            $table->string('subject', 64)->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip_hash', 80)->nullable();
            $table->string('user_agent_hash', 80)->nullable();
            $table->boolean('security')->default(false);
            $table->timestampTz('occurred_at');

            $table->index(['workspace_id', 'occurred_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('type', 100);
            $table->smallInteger('v')->default(1);
            $table->string('subject', 64);
            $table->unsignedBigInteger('subject_seq');
            $table->timestampTz('occurred_at');
            $table->string('actor', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('data');
            $table->timestampTz('sent_at')->nullable();

            $table->unique(['workspace_id', 'subject', 'subject_seq']);
        });

        Schema::create('outbox_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->string('consumer', 100);
            $table->uuid('event_id');
            $table->string('subject', 64);
            $table->unsignedBigInteger('subject_seq');
            $table->boolean('applied');
            $table->timestampTz('consumed_at');

            $table->unique(['consumer', 'event_id']);
            $table->index(['consumer', 'subject', 'subject_seq']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'system')"), 'rolname');
        if (array_diff(['app', 'system'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and system. Create them with docker/postgres/initdb.sh before migrating.');
        }

        $workspace = self::WORKSPACE;

        foreach (['audit_events', 'outbox_events', 'outbox_consumptions'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY workspace_isolation ON {$table}
                    USING ({$workspace}) WITH CHECK ({$workspace});
                SQL);
        }

        DB::unprepared(<<<'SQL'
            REVOKE UPDATE ON audit_events FROM app;
            REVOKE UPDATE ON outbox_events FROM app;
            REVOKE UPDATE ON outbox_consumptions FROM app;

            GRANT SELECT (id, workspace_id, type, v, subject, subject_seq, occurred_at, actor, request_id, data, sent_at)
                ON outbox_events TO system;
            GRANT UPDATE (sent_at) ON outbox_events TO system;

            -- UPDATE re-checks the new row against the SELECT policy, so a sent row must stay readable.
            CREATE POLICY relay_select ON outbox_events FOR SELECT TO system
                USING (true);
            CREATE POLICY relay_mark_sent ON outbox_events FOR UPDATE TO system
                USING (sent_at IS NULL) WITH CHECK (sent_at IS NOT NULL);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_consumptions');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_events');
    }
};

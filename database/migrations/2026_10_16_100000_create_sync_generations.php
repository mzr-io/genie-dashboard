<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Fetch comparison data together as a sync group (Story 2.20).
|
|  sync_targets      gains `group_primary_target_id`: a comparison target names its primary and shares the primary's `sync_group_id`. The
|                    dispatcher bumps only the primary's `dispatch_seq`; the comparison's `applied_seq` follows the primary's fence, so the
|                    `applied_seq <= dispatch_seq` check is relaxed for a target in another target's group (`sync_group_id <> id`). A primary has at most one comparison. `system` may
|                    also read `group_primary_target_id` (and the policy lets it see a linked comparison, which may have no schedule of its
|                    own) to count the group's hot subscriptions; it can change nothing new.
|  sync_generations  Ingestion: one row per committed group run, IDs, ints and enums only (never a body or a value). `complete` is true only when
|                    both sides succeeded in that run; a failed side keeps no payload id. Compute-facing reads use complete rows only.
|                    A tenant table under row-level security; `app` has SELECT and INSERT only (a generation is immutable).
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'maintenance', 'system')"), 'rolname');
        if (array_diff(['app', 'maintenance', 'system'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app, maintenance and system. Create them with docker/postgres/initdb.sh before migrating.');
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<SQL
            ALTER TABLE sync_targets ADD COLUMN group_primary_target_id uuid REFERENCES sync_targets (id) ON DELETE SET NULL;
            ALTER TABLE sync_targets ADD CONSTRAINT sync_targets_group_primary_check CHECK (group_primary_target_id IS NULL OR group_primary_target_id <> id);
            CREATE UNIQUE INDEX sync_targets_group_primary_unique ON sync_targets (workspace_id, group_primary_target_id) WHERE group_primary_target_id IS NOT NULL AND retired_at IS NULL;

            ALTER TABLE sync_targets DROP CONSTRAINT sync_targets_seq_check;
            ALTER TABLE sync_targets ADD CONSTRAINT sync_targets_seq_check
                CHECK (payload_seq >= 0 AND dispatch_seq >= 0 AND applied_seq >= 0 AND (applied_seq <= dispatch_seq OR group_primary_target_id IS NOT NULL));

            GRANT SELECT (group_primary_target_id) ON sync_targets TO system;
            DROP POLICY dispatch_select ON sync_targets;
            CREATE POLICY dispatch_select ON sync_targets FOR SELECT TO system
                USING (retired_at IS NULL AND (next_due_at IS NOT NULL OR group_primary_target_id IS NOT NULL));

            CREATE TABLE sync_generations (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                sync_group_id uuid NOT NULL,
                primary_target_id uuid NOT NULL REFERENCES sync_targets (id) ON DELETE CASCADE,
                comparison_target_id uuid NOT NULL REFERENCES sync_targets (id) ON DELETE CASCADE,
                dispatch_seq bigint NOT NULL,
                primary_ok boolean NOT NULL,
                comparison_ok boolean NOT NULL,
                complete boolean NOT NULL,
                primary_payload_id uuid,
                comparison_payload_id uuid,
                primary_payload_seq bigint,
                comparison_payload_seq bigint,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT sync_generations_complete_check CHECK (complete = (primary_ok AND comparison_ok)),
                CONSTRAINT sync_generations_dispatch_check CHECK (dispatch_seq >= 1),
                CONSTRAINT sync_generations_payload_check CHECK (
                    (NOT primary_ok OR (primary_payload_id IS NOT NULL AND primary_payload_seq IS NOT NULL))
                    AND (NOT comparison_ok OR (comparison_payload_id IS NOT NULL AND comparison_payload_seq IS NOT NULL))
                )
            );

            CREATE UNIQUE INDEX sync_generations_dispatch_unique ON sync_generations (workspace_id, sync_group_id, dispatch_seq);
            CREATE INDEX sync_generations_complete_index ON sync_generations (workspace_id, sync_group_id, dispatch_seq DESC) WHERE complete;

            ALTER TABLE sync_generations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE sync_generations FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON sync_generations
                USING ({$workspace}) WITH CHECK ({$workspace});

            REVOKE UPDATE ON sync_generations FROM app;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS sync_generations CASCADE;
            DROP POLICY IF EXISTS dispatch_select ON sync_targets;
            CREATE POLICY dispatch_select ON sync_targets FOR SELECT TO system
                USING (retired_at IS NULL AND next_due_at IS NOT NULL);
            REVOKE SELECT (group_primary_target_id) ON sync_targets FROM system;
            DROP INDEX IF EXISTS sync_targets_group_primary_unique;
            ALTER TABLE sync_targets DROP CONSTRAINT IF EXISTS sync_targets_seq_check;
            ALTER TABLE sync_targets DROP COLUMN IF EXISTS group_primary_target_id;
            UPDATE sync_targets SET applied_seq = dispatch_seq WHERE applied_seq > dispatch_seq;
            ALTER TABLE sync_targets ADD CONSTRAINT sync_targets_seq_check
                CHECK (payload_seq >= 0 AND dispatch_seq >= 0 AND applied_seq >= 0 AND applied_seq <= dispatch_seq);
            SQL);
    }
};

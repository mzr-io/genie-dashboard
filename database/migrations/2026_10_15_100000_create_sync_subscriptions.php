<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Refresh only what is watched, within budgets (Story 2.19).
|
|  sync_subscriptions  Ingestion: who watches a sync target. One row per (target, Block version, role, compute context): the refresh interval
|                      copied at subscribe time, the resolved period, `last_access_at` (moved by a throttled touch) and `hot_until`
|                      (`last_access_at` plus the `sync.hot_window` setting; null while that setting is unset). `block_version_id` is an opaque
|                      UUID and `compute_context` an opaque string: no foreign key leaves the module. A tenant table under row-level security;
|                      the dispatcher (role `system`) has column-limited SELECT of the hot rows only.
|  sync_targets        gains `user_scoped` (the target belongs to user-bound data, so it can go cold and be purged), `created_by_membership_id`
|                      (the new-cold-key budget counts these) and `budget_limited` (set by the dispatcher). `system` may also UPDATE
|                      `budget_limited`, and nothing else new.
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
            ALTER TABLE sync_targets ADD COLUMN user_scoped boolean NOT NULL DEFAULT false;
            ALTER TABLE sync_targets ADD COLUMN created_by_membership_id uuid;
            ALTER TABLE sync_targets ADD COLUMN budget_limited boolean NOT NULL DEFAULT false;
            CREATE INDEX sync_targets_user_scoped_index ON sync_targets (workspace_id, created_by_membership_id, created_at) WHERE user_scoped;

            CREATE TABLE sync_subscriptions (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                sync_target_id uuid NOT NULL REFERENCES sync_targets (id) ON DELETE CASCADE,
                block_version_id uuid NOT NULL,
                role varchar(10) NOT NULL,
                compute_context varchar(255) NOT NULL DEFAULT '',
                refresh_interval_seconds integer NOT NULL,
                period_start date,
                period_end date,
                last_access_at timestamptz NOT NULL,
                hot_until timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                CONSTRAINT sync_subscriptions_role_check CHECK (role IN ('primary', 'comparison')),
                CONSTRAINT sync_subscriptions_interval_check CHECK (refresh_interval_seconds >= 1)
            );

            CREATE UNIQUE INDEX sync_subscriptions_identity_unique
                ON sync_subscriptions (workspace_id, sync_target_id, block_version_id, role, compute_context);
            CREATE INDEX sync_subscriptions_hot_index ON sync_subscriptions (hot_until) WHERE hot_until IS NOT NULL;
            CREATE INDEX sync_subscriptions_target_index ON sync_subscriptions (sync_target_id);

            ALTER TABLE sync_subscriptions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE sync_subscriptions FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON sync_subscriptions
                USING ({$workspace}) WITH CHECK ({$workspace});

            -- The dispatcher (role `system`): the hot rows only, and the columns it needs to rank and pick an interval.
            GRANT SELECT (workspace_id, sync_target_id, refresh_interval_seconds, last_access_at, hot_until) ON sync_subscriptions TO system;
            CREATE POLICY dispatch_select ON sync_subscriptions FOR SELECT TO system
                USING (hot_until IS NOT NULL AND hot_until > now());

            -- It may also record that a target was held to a wider interval.
            GRANT UPDATE (budget_limited) ON sync_targets TO system;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS sync_subscriptions CASCADE;
            DROP INDEX IF EXISTS sync_targets_user_scoped_index;
            ALTER TABLE sync_targets DROP COLUMN IF EXISTS budget_limited;
            ALTER TABLE sync_targets DROP COLUMN IF EXISTS created_by_membership_id;
            ALTER TABLE sync_targets DROP COLUMN IF EXISTS user_scoped;
            SQL);
    }
};

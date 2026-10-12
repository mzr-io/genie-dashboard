<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Personal dashboards (Story 4.4), owned by Dashboards.
|
|  dashboards       one row per dashboard of a member. `membership_id` is the owner (a plain column: no foreign key across modules). A
|                   member has at most one Overview (`is_overview`, a partial unique index); it is created on first use with `revision`
|                   0 and is empty in this epic. `revision` is the compare-and-set counter every layout command (Story 4.9 on) bumps.
|  dashboard_items  one row per Block on a dashboard (unique per dashboard and Block), holding where it sits on the 12-column grid
|                   (`col`, `row` in 60 px units), its width in columns and its height preset, and whether it is minimized. A Block is
|                   referenced by id and always renders its current published version, so a publish never moves a layout.
|
|  Tenant tables: non-null workspace_id, UUIDv7 keys (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the
|  workspace_isolation policy. Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE; the layout commands that
|  remove an item are Story 4.9's.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<'SQL'
            CREATE TABLE dashboards (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                membership_id uuid NOT NULL,
                name varchar(120) NOT NULL DEFAULT 'Overview',
                is_overview boolean NOT NULL DEFAULT false,
                revision integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT dashboards_revision_check CHECK (revision >= 0),
                CONSTRAINT dashboards_name_check CHECK (char_length(name) BETWEEN 1 AND 120)
            );
            CREATE INDEX dashboards_member_index ON dashboards (workspace_id, membership_id, created_at);
            CREATE UNIQUE INDEX dashboards_one_overview_unique ON dashboards (workspace_id, membership_id) WHERE is_overview;

            CREATE TABLE dashboard_items (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                dashboard_id uuid NOT NULL REFERENCES dashboards (id),
                block_id uuid NOT NULL,
                col smallint NOT NULL DEFAULT 0,
                row integer NOT NULL DEFAULT 0,
                width smallint NOT NULL DEFAULT 3,
                height varchar(8) NOT NULL DEFAULT 'small',
                minimized boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT dashboard_items_col_check CHECK (col BETWEEN 0 AND 11),
                CONSTRAINT dashboard_items_row_check CHECK (row >= 0),
                CONSTRAINT dashboard_items_width_check CHECK (width IN (3, 4, 6, 8, 12) AND col + width <= 12),
                CONSTRAINT dashboard_items_height_check CHECK (height IN ('small', 'medium', 'large'))
            );
            CREATE UNIQUE INDEX dashboard_items_block_unique ON dashboard_items (dashboard_id, block_id);
            CREATE INDEX dashboard_items_workspace_index ON dashboard_items (workspace_id, dashboard_id);
            SQL);

        foreach (['dashboards', 'dashboard_items'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY workspace_isolation ON {$table}
                    USING ({$workspace}) WITH CHECK ({$workspace});
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS dashboard_items CASCADE;
            DROP TABLE IF EXISTS dashboards CASCADE;
            SQL);
    }
};

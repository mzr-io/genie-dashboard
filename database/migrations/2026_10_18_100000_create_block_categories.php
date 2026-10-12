<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Block categories (Story 3.4), owned by Blocks. Expand-only: a new table and a nullable column; nothing is dropped or rewritten.
|
|  block_categories  a Workspace's category of Blocks: a name (unique per Workspace, ignoring case) and an `archived` flag (default
|                    false). Management and ordering come with Epic 6. The "General" category is created lazily by the application, in
|                    the Workspace's transaction and idempotently, the first time the wizard or the categories are read.
|  blocks.category_id  the Block's category. Null until the lazy seed has run for the Workspace; the old varchar `blocks.category` stays
|                    for now (a later contract step drops it).
|
|  Tenant table: non-null workspace_id, UUIDv7 key (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the
|  workspace_isolation policy. Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE.
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

        DB::unprepared(<<<SQL
            CREATE TABLE block_categories (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                name varchar(64) NOT NULL,
                archived boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT block_categories_name_check CHECK (char_length(name) BETWEEN 1 AND 64 AND name = btrim(name))
            );
            CREATE UNIQUE INDEX block_categories_name_unique ON block_categories (workspace_id, lower(name));

            ALTER TABLE block_categories ENABLE ROW LEVEL SECURITY;
            ALTER TABLE block_categories FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON block_categories
                USING ({$workspace}) WITH CHECK ({$workspace});

            ALTER TABLE blocks ADD COLUMN category_id uuid REFERENCES block_categories (id);
            CREATE INDEX blocks_category_index ON blocks (category_id);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE blocks DROP COLUMN IF EXISTS category_id;
            DROP TABLE IF EXISTS block_categories CASCADE;
            SQL);
    }
};
